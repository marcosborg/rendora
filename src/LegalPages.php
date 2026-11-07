<?php
declare(strict_types=1);
namespace HomeAlone;

use Psr\Http\Message\ResponseInterface as Response;

final class LegalPages {
    private const PAGES = [
        ['legal', 'en/legal', 'Informação legal', 'Legal notice'],
        ['privacidade', 'en/privacy', 'Privacidade', 'Privacy'],
        ['cookies', 'en/cookies', 'Cookies', 'Cookies'],
        ['termos', 'en/terms', 'Termos de utilização', 'Terms of use'],
    ];

    public static function routes(): array {
        return self::PAGES;
    }

    public static function footer(bool $english): string {
        $label=$english?'Legal information':'Informação legal';
        $links='';
        foreach(self::PAGES as [$pt,$en,$ptTitle,$enTitle]){
            $links.='<a href="/'.($english?$en:$pt).'">'.($english?$enTitle:$ptTitle).'</a> ';
        }
        return '<nav class="legal-links" aria-label="'.$label.'">'.$links.'<a href="mailto:info@rendora.pt">info@rendora.pt</a></nav>';
    }

    public static function render(Response $response, array $page, bool $english): Response {
        [$pt,$en,$ptTitle,$enTitle]=$page;
        $title=$english?$enTitle:$ptTitle;
        $lang=$english?'en':'pt-PT';
        $other=$english?'<a href="/'.$pt.'" lang="pt-PT">PT</a>':'<a href="/'.$en.'" lang="en">EN</a>';
        $home=$english?'/en/':'/';
        $draft=$english?'Draft for review. Operator details and outstanding policies are to be completed.':'Minuta para revisão. Identificação do operador e políticas pendentes por completar.';
        $sections='';
        foreach(self::sections($pt,$english) as [$heading,$body]){
            $sections.='<section><h2>'.self::escape($heading).'</h2><p>'.self::escape($body).'</p></section>';
        }
        $footer=self::footer($english);
        $response->getBody()->write(<<<HTML
<!doctype html>
<html lang="$lang">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>$title | Rendora</title>
<link rel="icon" href="/brand/favicon.svg" type="image/svg+xml">
<style>
*{box-sizing:border-box}body{margin:0;background:#f3f8f6;color:#173a38;font:1rem/1.65 system-ui,sans-serif}
header,footer{background:#fff;padding-block:1rem}header{border-bottom:1px solid #d5e5e0}
footer{border-top:1px solid #d5e5e0}a{color:#125c59;text-underline-offset:.2em}
.wrap{width:min(100% - 2rem,48rem);margin-inline:auto}header .wrap,.legal-links{display:flex;flex-wrap:wrap;gap:.6rem 1rem;align-items:center}
header .wrap{justify-content:space-between}header img{display:block;width:8.5rem}
main{padding-block:2rem 3rem}h1,h2{line-height:1.2}h1{font-size:clamp(2rem,6vw,3rem)}h2{font-size:1.2rem}
section{margin-top:1.75rem}.draft{background:#fff;border-left:3px solid #125c59;padding:.65rem 1rem}
</style>
</head>
<body>
<header><div class="wrap"><a href="$home"><img src="/brand/rendora-light.svg" alt="Rendora" width="136"></a>$other</div></header>
<main class="wrap"><h1>$title</h1><p class="draft">$draft</p>$sections</main>
<footer><div class="wrap">$footer</div></footer>
</body>
</html>
HTML);
        return $response->withHeader('Content-Type','text/html; charset=utf-8')->withHeader('X-Robots-Tag','noindex');
    }

    private static function sections(string $page, bool $english): array {
        return match($page){
            'legal'=>$english?[
                ['Operator','Legal name: [to complete]. Registered address: [to complete]. Tax or company number: [to complete]. Registration authority and number, where applicable: [to complete].'],
                ['Contact','General enquiries and support: info@rendora.pt.'],
                ['About the service','Rendora is rental management software. Commercial subscriptions are intended for customers acting for business or professional purposes. The public demo uses fictional data and does not take real payments.'],
            ]:[
                ['Entidade responsável','Denominação legal: [por preencher]. Morada ou sede: [por preencher]. NIF ou NIPC: [por preencher]. Entidade e número de registo, quando aplicável: [por preencher].'],
                ['Contacto','Informações e apoio: info@rendora.pt.'],
                ['Sobre o serviço','A Rendora é um software de gestão de arrendamentos. As subscrições comerciais destinam-se a clientes que actuam no âmbito da sua actividade empresarial ou profissional. A demo pública usa dados fictícios e não aceita pagamentos reais.'],
            ],
            'privacidade'=>$english?[
                ['Who handles your data','For Rendora’s own account administration, support and billing, the controller is [operator and address to complete]. Contact: info@rendora.pt. Where a customer uses Rendora to manage personal data on its behalf, that customer determines the purposes and Rendora acts on its instructions as set out in a separate processing agreement.'],
                ['What is processed and why','Account and contact details support account access, service delivery and enquiries. Security logs help prevent abuse. Where applicable, billing records are kept to meet legal obligations. Contract performance or pre-contractual steps, legitimate interests in service security or responding to enquiries, and legal obligations apply as appropriate to the purpose.'],
                ['Property enquiries','Contact details and the message are made available to the organisation responsible for the listing so that it can respond. The organisation determines how it handles the enquiry after receipt.'],
                ['Recipients, transfers and retention','Hosting, email and payment providers may process data needed for their services. Provider details, transfer safeguards and retention periods or criteria: [to complete after verification].'],
                ['Your rights','Depending on the circumstances, you may ask for access, correction, deletion, restriction or portability, object to processing or withdraw consent. Contact info@rendora.pt. You may also lodge a complaint with the Portuguese data protection authority (CNPD).'],
            ]:[
                ['Quem trata os teus dados','Para gestão de contas, apoio e facturação próprios da Rendora, o responsável é [entidade e morada por preencher]. Contacto: info@rendora.pt. Quando um cliente usa a Rendora para gerir dados pessoais por sua conta, esse cliente define as finalidades e a Rendora actua segundo as suas instruções, nos termos de um acordo de tratamento separado.'],
                ['Que dados são tratados e para quê','Os dados de conta e contacto servem para gerir o acesso, prestar o serviço e responder a pedidos. Os registos de segurança ajudam a prevenir abusos. Quando aplicável, os dados de facturação são conservados para cumprir obrigações legais. Consoante a finalidade, o fundamento é a execução do contrato ou diligências pré-contratuais, o interesse legítimo na segurança ou na resposta a pedidos, ou o cumprimento de obrigações legais.'],
                ['Pedidos sobre imóveis','Os dados de contacto e a mensagem são disponibilizados à organização responsável pelo anúncio para que possa responder. Essa organização determina como trata o pedido após a recepção.'],
                ['Destinatários, transferências e conservação','Os fornecedores de alojamento, email e pagamentos podem tratar os dados necessários aos seus serviços. Identificação dos fornecedores, garantias de transferência e prazos ou critérios de conservação: [por preencher após verificação].'],
                ['Os teus direitos','Consoante o caso, podes pedir acesso, rectificação, apagamento, limitação ou portabilidade, opor-te ao tratamento ou retirar o consentimento. Contacta info@rendora.pt. Também podes apresentar uma reclamação à Comissão Nacional de Protecção de Dados (CNPD).'],
            ],
            'cookies'=>$english?[
                ['Session cookies','Rendora uses ha_session to keep you signed in and secure your session. The public demo uses __Host-rendora_demo on its own domain. These cookies expire after 24 hours or when you sign out.'],
                ['Browser storage','The selected workspace is stored in the browser for the current tab session.'],
                ['Optional tracking','No advertising or analytics tags are included in the website code covered by this notice. This notice must be updated before adding optional tracking, with consent controls where required.'],
            ]:[
                ['Cookies de sessão','A Rendora usa a cookie ha_session para manter a sessão iniciada e proteger o acesso. A demo pública usa __Host-rendora_demo no seu próprio domínio. Estas cookies expiram ao fim de 24 horas ou quando terminas sessão.'],
                ['Armazenamento no navegador','O espaço de trabalho seleccionado fica guardado no navegador durante a sessão do separador.'],
                ['Medição opcional','O código do site abrangido por este aviso não inclui etiquetas de publicidade ou análise de tráfego. Este aviso deve ser actualizado antes de acrescentar medição opcional, com controlos de consentimento quando exigidos.'],
            ],
            'termos'=>$english?[
                ['Professional subscriptions','The commercial service is intended for customers acting for business or professional purposes. The person contracting on behalf of an organisation must have authority to do so. Access to a portal as a resident, owner or technician does not in itself create a paid subscription.'],
                ['Access and use','Keep credentials confidential and restrict access to authorised users. Only upload information you have a right to use. Public demo data is fictional and the demo must not be used to submit real personal data.'],
                ['Commercial terms','Plan, price, tax treatment, renewal, cancellation, support and handling of data on termination: [to complete before offering paid subscriptions].'],
                ['Payments and documents','Rent payment records and documents generated by Rendora for account reconciliation are operational records. Applicable tax documents must be issued separately.'],
            ]:[
                ['Subscrições profissionais','O serviço comercial destina-se a clientes que actuam no âmbito da sua actividade empresarial ou profissional. Quem contrata em nome de uma organização deve ter poderes para o fazer. O acesso ao portal como morador, proprietário ou técnico não cria, por si só, uma subscrição paga.'],
                ['Acesso e utilização','Mantém as credenciais confidenciais e limita o acesso a utilizadores autorizados. Carrega apenas informação que tens direito a utilizar. Os dados da demo pública são fictícios e a demo não deve ser usada para submeter dados pessoais reais.'],
                ['Condições comerciais','Plano, preço, impostos, renovação, cancelamento, apoio e tratamento dos dados no termo do contrato: [por preencher antes da oferta de subscrições pagas].'],
                ['Pagamentos e documentos','Os registos de pagamentos de renda e os documentos gerados pela Rendora para conferência são registos operacionais. Os documentos fiscais aplicáveis devem ser emitidos separadamente.'],
            ],
        };
    }

    private static function escape(string $text): string {
        return htmlspecialchars($text,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    }
}
