<?php
declare(strict_types=1);
namespace HomeAlone;
use Psr\Http\Message\UploadedFileInterface;
final class Documents {
    public function __construct(private Database $db) {}
    public function authorize(Scope $s,string $type,string $id,bool $write=false): void {
        if($type==='ticket')$s->ticket($id);elseif($type==='contract'){$s->contract($id);if($write)$s->requireRoles('ADMIN','MANAGER');}elseif($type==='property'){$s->property($id);if($write)$s->requireRoles('ADMIN','MANAGER');}elseif($type==='conversation')$s->conversation($id);else throw new HttpError(422,'Tipo de documento inválido.');
    }
    public function list(Scope $s,string $type,string $id): array {$this->authorize($s,$type,$id);return $this->db->all('SELECT id,name,mime_type,size,created_at,entity_type,entity_id FROM documents WHERE company_id=? AND entity_type=? AND entity_id=? ORDER BY created_at DESC',[$s->companyId,$type,$id]);}
    public function upload(Scope $s,string $type,string $id,UploadedFileInterface $file): array {
        $this->authorize($s,$type,$id,true);if($file->getError()!==UPLOAD_ERR_OK||!$file->getSize()||$file->getSize()>10*1024*1024)throw new HttpError(422,'Ficheiro inválido. Limite: 10 MB.');$stream=$file->getStream();$head=$stream->read(8192);$stream->rewind();$mime=(new \finfo(FILEINFO_MIME_TYPE))->buffer($head);if(!in_array($mime,['application/pdf','image/jpeg','image/png','image/webp','text/plain']))throw new HttpError(422,'Use PDF, JPG, PNG, WebP ou TXT.');$name=basename(str_replace('\\','/',$file->getClientFilename()??'documento'));$name=preg_replace('/[\x00-\x1f\x7f]/','',$name);$path=$s->companyId.'/'.Database::id();$directory=dirname(__DIR__).'/storage/uploads/'.$s->companyId;if(!is_dir($directory))mkdir($directory,0700,true);$target=dirname(__DIR__).'/storage/uploads/'.$path;$file->moveTo($target);chmod($target,0600);
        try{$doc=$this->db->insert('documents',['company_id'=>$s->companyId,'entity_type'=>$type,'entity_id'=>$id,'name'=>mb_substr($name,0,200),'storage_path'=>$path,'mime_type'=>$mime,'size'=>$file->getSize(),'author_id'=>$s->user['id']]);$s->audit('document.uploaded',$doc['id']);unset($doc['storage_path']);return $doc;}catch(\Throwable $e){unlink($target);throw $e;}
    }
    public function download(Scope $s,string $id): array {$doc=$s->row('documents',$id);$this->authorize($s,$doc['entity_type'],$doc['entity_id']);$path=dirname(__DIR__).'/storage/uploads/'.$doc['storage_path'];if(!is_file($path))throw new HttpError(404,'Ficheiro não encontrado.');return [...$doc,'path'=>$path];}
    public function photo(Scope $s,string $propertyId,UploadedFileInterface $file): array {
        $s->requireRoles('ADMIN','MANAGER');$s->row('properties',$propertyId);
        $head=$file->getStream()->read(8192);$file->getStream()->rewind();$mime=(new \finfo(FILEINFO_MIME_TYPE))->buffer($head);if(!in_array($mime,['image/jpeg','image/png','image/webp']))throw new HttpError(422,'Use uma fotografia JPG, PNG ou WebP.');
        return $this->db->transaction(function()use($s,$propertyId,$file){$p=$s->row('properties',$propertyId);$photos=json_decode($p['photos'],true);if(count($photos)>=20)throw new HttpError(422,'Limite de 20 fotografias atingido.');$doc=$this->upload($s,'property',$propertyId,$file);$url='/api/v1/public/photos/'.$doc['id'];$photos[]=$url;$this->db->run('UPDATE properties SET photos=? WHERE id=? AND company_id=?',[json_encode($photos),$propertyId,$s->companyId]);return ['url'=>$url,'document_id'=>$doc['id']];});
    }
}
