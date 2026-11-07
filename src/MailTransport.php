<?php
declare(strict_types=1);
namespace HomeAlone;

final class MailTransport {
    public static function configured(): bool {
        if(env('SMTP_HOST')!=='')return env('SMTP_USER')!==''&&env('SMTP_PASSWORD')!==''&&env('EMAIL_FROM')!=='';
        return env('RESEND_API_KEY')!==''&&env('EMAIL_FROM')!=='';
    }

    public static function smtp(array $message,string $key): void {
        $host=env('SMTP_HOST');
        $port=env('SMTP_PORT','465');
        $from=env('EMAIL_FROM');
        $to=$message['email'];
        if(!preg_match('/^[a-z0-9.-]+$/iD',$host)||!in_array($port,['465','587'],true)
            ||!filter_var($from,FILTER_VALIDATE_EMAIL)||!filter_var($to,FILTER_VALIDATE_EMAIL)){
            throw new HttpError(503,'Configuração SMTP inválida.');
        }

        $id=hash('sha256',$key).'@'.substr(strrchr($from,'@'),1);
        $subject=mb_encode_mimeheader(str_replace(["\r","\n"],' ',$message['subject']),'UTF-8','B',"\r\n",9);
        $body=chunk_split(base64_encode($message['body']),76,"\r\n");
        $data=implode("\r\n",[
            'From: <'.$from.'>','To: <'.$to.'>','Date: '.gmdate('D, d M Y H:i:s').' +0000',
            'Message-ID: <'.$id.'>','Subject: '.$subject,'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8','Content-Transfer-Encoding: base64','','',
        ]).$body;
        $stream=fopen('php://temp','w+b');
        fwrite($stream,$data);
        rewind($stream);
        $curl=curl_init(($port==='465'?'smtps':'smtp').'://'.$host.':'.$port);
        try {
            curl_setopt_array($curl,[
                CURLOPT_USERNAME=>env('SMTP_USER'),CURLOPT_PASSWORD=>env('SMTP_PASSWORD'),
                CURLOPT_USE_SSL=>CURLUSESSL_ALL,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
                CURLOPT_MAIL_FROM=>'<'.$from.'>',CURLOPT_MAIL_RCPT=>['<'.$to.'>'],
                CURLOPT_UPLOAD=>true,CURLOPT_INFILE=>$stream,CURLOPT_INFILESIZE=>strlen($data),
                CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>20,CURLOPT_RETURNTRANSFER=>true,
            ]);
            if(curl_exec($curl)===false)throw new HttpError(502,'O servidor SMTP não aceitou o envio.');
        } finally {
            curl_close($curl);
            fclose($stream);
        }
    }
}
