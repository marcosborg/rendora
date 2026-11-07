<?php
declare(strict_types=1);
namespace HomeAlone;

final class PayPalSandboxClient {
    private const BASE='https://api-m.sandbox.paypal.com';
    private array $tokens=[];

    public function __construct(private array $apps, private ?\Closure $transport=null) {}

    public static function configured(): self {
        $raw=env('PAYPAL_SANDBOX_APPS');
        return new self($raw!==''?(array)json_decode($raw,true,32,JSON_THROW_ON_ERROR):[]);
    }

    public function app(string $key): array {
        $app=$this->apps[$key]??null;
        if (!is_array($app)||empty($app['client_id'])||empty($app['client_secret'])) {
            throw new HttpError(503,'Aplicação PayPal Sandbox por configurar.');
        }
        return $app;
    }

    public function apps(): array {
        return $this->apps;
    }

    public function call(string $appKey,string $method,string $path,array|string|null $data=null,?string $requestId=null): array {
        $app=$this->app($appKey);
        if (!isset($this->tokens[$appKey])) {
            $auth=$this->request('POST','/v1/oauth2/token',[
                'Authorization: Basic '.base64_encode($app['client_id'].':'.$app['client_secret']),
                'Content-Type: application/x-www-form-urlencoded',
            ],'grant_type=client_credentials');
            if (empty($auth['access_token'])) throw new HttpError(502,'Falha na autenticação PayPal Sandbox.');
            $this->tokens[$appKey]=$auth['access_token'];
        }
        $headers=['Authorization: Bearer '.$this->tokens[$appKey],'Content-Type: application/json'];
        if ($requestId!==null) $headers[]='PayPal-Request-Id: '.$requestId;
        return $this->request($method,$path,$headers,is_string($data)?$data:($data===null?null:($data===[]?'{}':json_encode($data,JSON_THROW_ON_ERROR))));
    }

    private function request(string $method,string $path,array $headers,?string $body): array {
        $headers[]='Accept: application/json';
        if ($this->transport) return ($this->transport)($method,self::BASE.$path,$headers,$body);
        $curl=curl_init(self::BASE.$path);
        $options=[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>18];
        if ($body!==null) $options[CURLOPT_POSTFIELDS]=$body;
        try {
            curl_setopt_array($curl,$options);
            $response=curl_exec($curl);
            $code=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
        } finally { curl_close($curl); }
        if (!is_string($response)||$code<200||$code>=300) throw new HttpError(502,'O PayPal Sandbox não confirmou a operação.');
        return (array)json_decode($response,true,32,JSON_THROW_ON_ERROR);
    }
}
