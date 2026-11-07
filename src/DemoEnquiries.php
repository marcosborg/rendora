<?php
declare(strict_types=1);
namespace HomeAlone;

final class DemoEnquiries {
    public function __construct(private Database $db, private Auth $auth) {}

    public function submit(array $input, string $ip): void {
        if (defined('RENDORA_PUBLIC_DEMO')) throw new HttpError(403,'Ação indisponível na demonstração pública.');
        if (($input['website']??'')!=='') return;

        $name=Validate::text($input,'name',2,120);
        $email=Validate::email($input);
        $company=Validate::text($input,'company',2,150);
        $units=Validate::choice($input,'units',['1-10','11-40','41+']);
        $key='demo-enquiry:'.hash('sha256',json_encode([$email,$name,$company,$units],JSON_THROW_ON_ERROR));
        $cutoff=gmdate('Y-m-d H:i:s',time()-900);
        if ($this->db->one('SELECT id FROM jobs WHERE dedupe_key LIKE ? AND created_at>=? LIMIT 1',[$key.':%',$cutoff])) return;

        $this->auth->throttle('demo-enquiry:'.$ip,5,900);
        $this->db->transaction(function()use($name,$email,$company,$units,$key,$cutoff){
            if ($this->db->one('SELECT id FROM jobs WHERE dedupe_key LIKE ? AND created_at>=? LIMIT 1',[$key.':%',$cutoff])) return;
            $this->db->insert('jobs',[
                'type'=>'EMAIL',
                'payload'=>json_encode([
                    'email'=>'info@rendora.pt',
                    'subject'=>'Rendora · Pedido de demonstração',
                    'body'=>"Nome: $name\nEmail profissional: $email\nEmpresa ou atividade: $company\nUnidades geridas: $units",
                ],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
                'dedupe_key'=>$key.':'.Database::id(),
            ]);
        });
    }
}
