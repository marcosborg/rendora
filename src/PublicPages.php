<?php
declare(strict_types=1);
namespace HomeAlone;

use Psr\Http\Message\ResponseInterface as Response;

final class PublicPages {
    private const ABOUT_PT = '/sobre';
    private const ABOUT_EN = '/en/about';

    public static function about(Response $response, bool $english): Response {
        $language=$english?'en':'pt-PT';
        $title=$english?'Rendora · Property and rental management':'Rendora · Gestão de imóveis e arrendamentos';
        $description=$english
            ?'Manage properties, rental contracts, rent and maintenance in one place. Explore the Rendora demo.'
            :'Gere imóveis, contratos de arrendamento, rendas e manutenção num só lugar. Experimenta a demo da Rendora.';
        $url=self::escape(self::origin().($english?self::ABOUT_EN:self::ABOUT_PT));
        $switch=$english?self::ABOUT_PT:self::ABOUT_EN;
        $switchLanguage=$english?'pt-PT':'en';
        $switchText=$english?'Português':'English';
        $login=$english?'/en/':'/';
        $loginLabel=$english?'Sign in':'Iniciar sessão';
        $demo=(defined('RENDORA_PUBLIC_DEMO')?'':'https://demo.rendora.pt').$login;
        $demoLabel=$english?'Explore the demo':'Experimentar a demo';
        $headline=$english?'Manage your rentals in one place':'Gere os teus arrendamentos num só lugar';
        $intro=$english
            ?'Rendora brings together properties, contracts, rent and maintenance. Give managers, residents, owners and technicians their own space to work.'
            :'A Rendora reúne imóveis, contratos, rendas e manutenção. Dá a gestores, moradores, proprietários e técnicos um espaço próprio para trabalhar.';
        $features=$english?[
            ['Properties and contracts','Keep track of each property, who lives there and when contracts end.'],
            ['Rent and expenses','See what has been paid, what is overdue and what each property costs to run.'],
            ['Maintenance and messages','Record issues, assign work and follow requests through to completion.'],
        ]:[
            ['Imóveis e contratos','Acompanha cada imóvel, quem lá vive e quando terminam os contratos.'],
            ['Rendas e despesas','Vê o que foi pago, o que está em atraso e quanto custa gerir cada imóvel.'],
            ['Manutenção e mensagens','Regista avarias, atribui trabalhos e acompanha os pedidos até à resolução.'],
        ];
        $cards='';
        foreach($features as [$heading,$text]){
            $cards.='<article><h2>'.self::escape($heading).'</h2><p>'.self::escape($text).'</p></article>';
        }
        $navLabel=$english?'Main navigation':'Navegação principal';
        $featuresLabel=$english?'Features':'Funcionalidades';
        $demo=self::escape($demo);
        $title=self::escape($title);
        $description=self::escape($description);
        $alternates=self::aboutAlternates(false);
        $image=self::escape(self::origin().'/brand/rendora-light.svg');
        $locale=$english?'en_GB':'pt_PT';
        $html=<<<HTML
<!doctype html>
<html lang="$language">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#125c59">
    <title>$title</title>
    <meta name="description" content="$description">
    <meta property="og:site_name" content="Rendora">
    <meta property="og:type" content="website">
    <meta property="og:title" content="$title">
    <meta property="og:description" content="$description">
    <meta property="og:url" content="$url">
    <meta property="og:locale" content="$locale">
    <meta property="og:image" content="$image">
    <link rel="icon" href="/brand/favicon.svg" type="image/svg+xml">
    <link rel="canonical" href="$url">
    $alternates
    <style>
        :root{font-family:system-ui,sans-serif;color:#173a38;background:#f3f8f6}
        *{box-sizing:border-box}body{margin:0}a{color:inherit}a:focus-visible{outline:3px solid #218b80;outline-offset:4px}
        .wrap{width:min(100% - 2.5rem,70rem);margin-inline:auto}
        header{border-bottom:1px solid #d5e5e0;background:#fff}
        header .wrap,nav{display:flex;align-items:center;flex-wrap:wrap;gap:1rem}
        header .wrap{justify-content:space-between;padding-block:1rem}header img{display:block;width:8.5rem;height:auto}
        nav a{font-weight:650;text-underline-offset:.2em}
        .hero{padding-block:clamp(3rem,10vw,7rem) 3rem;max-width:52rem}
        h1{font-size:clamp(2.5rem,7vw,5.5rem);line-height:1.02;letter-spacing:-.055em;margin:0}
        p{line-height:1.65;color:#3c5956}.hero p{max-width:39rem;font-size:1.2rem;margin-block:1.5rem}
        .cta{display:inline-block;background:#125c59;color:#fff;border-radius:.6rem;padding:.85rem 1.2rem;font-weight:750;text-decoration:none}
        .cta:hover{background:#0c4946}
        .features{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,16rem),1fr));gap:1rem;margin-block:0 4rem}
        article{border:1px solid #d5e5e0;border-radius:1rem;background:#fff;padding:1.4rem}
        h2{font-size:1.2rem;margin:0}article p{margin-bottom:0}footer{border-top:1px solid #d5e5e0;padding-block:1.25rem}
    </style>
</head>
<body>
    <header><div class="wrap">
        <img src="/brand/rendora-light.svg" alt="Rendora" width="136">
        <nav aria-label="$navLabel"><a href="$switch" lang="$switchLanguage" hreflang="$switchLanguage">$switchText</a><a href="$login">$loginLabel</a></nav>
    </div></header>
    <main class="wrap">
        <section class="hero"><h1>$headline</h1><p>$intro</p><a class="cta" href="$demo">$demoLabel</a></section>
        <section class="features" aria-label="$featuresLabel">$cards</section>
    </main>
    <footer><div class="wrap">Rendora</div></footer>
</body>
</html>
HTML;
        return self::html($response,$html);
    }

    public static function login(Response $response, string $shell, bool $english): Response {
        if($english){
            $shell=self::localiseShell(
                $shell,
                'Rendora · Sign in',
                'Sign in to Rendora to manage properties, rental contracts, rent and maintenance.',
                true
            );
        }
        return self::html($response,$shell)->withHeader('X-Robots-Tag','noindex');
    }

    public static function catalog(Response $response, string $shell, array $data, string $slug, bool $english): Response {
        $company=$data['company'];
        $title=$company['name'].($english?' · Rental properties | Rendora':' · Alojamentos | Rendora');
        $description=mb_substr($company['description']?:($english
            ?'Browse rental properties and ask the team for more information.'
            :'Explora os alojamentos e pede informações à equipa. Gestão de imóveis e arrendamentos com a Rendora.'),0,155);
        $shell=self::localiseShell($shell,$title,$description,$english);
        $otherLanguage=$english?'pt-PT':'en';
        $otherPath=($english?'/alojamentos/':'/en/properties/').rawurlencode($slug);
        $catalog='<main><a href="'.$otherPath.'" lang="'.$otherLanguage.'" hreflang="'.$otherLanguage.'">'.($english?'Português':'English').'</a><h1>'.self::escape($company['name']).'</h1><p>'.self::escape($description).'</p>';
        foreach($data['properties'] as $property){
            $price=$english?'€'.number_format($property['rent_cents']/100,2,'.',',').' / month':number_format($property['rent_cents']/100,2,',','.').' €/mês';
            $catalog.='<article><h2>'.self::escape($property['name']).'</h2><p>'.self::escape($property['city']).' · '.$price.'</p><p>'.self::escape($property['description']).'</p></article>';
        }
        $catalog.='<footer>'.($english?'Powered by Rendora · Property and rental management':'Uma plataforma Rendora · Gestão de imóveis e arrendamentos').'</footer></main>';
        if(!$english){
            $canonical=self::escape(self::origin().'/alojamentos/'.rawurlencode($slug));
            $shell=str_replace('</head>','<link rel="canonical" href="'.$canonical.'"></head>',$shell);
        }
        $response=self::html($response,str_replace('<div id="root"></div>','<div id="root">'.$catalog.'</div>',$shell));
        // The interface can be in English before property owners provide translated descriptions.
        return $english?$response->withHeader('X-Robots-Tag','noindex'):$response;
    }

    public static function sitemap(Response $response, Database $db): Response {
        $xml='<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">';
        if(!defined('RENDORA_PUBLIC_DEMO')){
            $alternates=self::aboutAlternates(true);
            foreach([self::ABOUT_PT,self::ABOUT_EN] as $path){
                $xml.='<url><loc>'.self::escape(self::origin().$path).'</loc>'.$alternates.'</url>';
            }
            $companies=$db->all("SELECT c.slug FROM companies c WHERE EXISTS (SELECT 1 FROM properties p WHERE p.company_id=c.id AND p.published=1 AND p.type IN ('APARTMENT','ROOM')) ORDER BY c.slug");
            foreach($companies as $company){
                $xml.='<url><loc>'.self::escape(self::origin().'/alojamentos/'.rawurlencode($company['slug'])).'</loc></url>';
            }
        }
        $response->getBody()->write($xml.'</urlset>');
        return $response->withHeader('Content-Type','application/xml; charset=utf-8');
    }

    public static function robots(Response $response): Response {
        $robots="User-agent: *\nDisallow: /api/\n";
        if(!defined('RENDORA_PUBLIC_DEMO'))$robots.='Sitemap: '.self::origin()."/sitemap.xml\n";
        $response->getBody()->write($robots);
        return $response->withHeader('Content-Type','text/plain; charset=utf-8');
    }

    private static function aboutAlternates(bool $xml): string {
        $prefix=$xml?'xhtml:':'';
        $end=$xml?'/>' : '>';
        $links='';
        foreach(['pt-PT'=>self::ABOUT_PT,'en'=>self::ABOUT_EN,'x-default'=>self::ABOUT_PT] as $language=>$path){
            $links.='<'.$prefix.'link rel="alternate" hreflang="'.$language.'" href="'.self::escape(self::origin().$path).'"'.$end;
        }
        return $links;
    }

    private static function localiseShell(string $html, string $title, string $description, bool $english): string {
        if($english){
            $html=str_replace(['<html lang="pt-PT">','content="pt_PT"'],['<html lang="en">','content="en_GB"'],$html);
        }
        $title=self::escape($title);
        $description=self::escape($description);
        $html=preg_replace_callback('/<title>.*?<\/title>/',fn()=>'<title>'.$title.'</title>',$html);
        return preg_replace_callback('/<meta (name="description"|property="og:title"|property="og:description")[^>]*>/',
            fn($match)=>'<meta '.$match[1].' content="'.($match[1]==='property="og:title"'?$title:$description).'"/>',
            $html
        );
    }

    private static function html(Response $response, string $body): Response {
        $response->getBody()->write($body);
        return $response->withHeader('Content-Type','text/html; charset=utf-8');
    }

    private static function origin(): string {
        return rtrim(env('APP_URL')?:'https://rendora.pt','/');
    }

    private static function escape(string $text): string {
        return htmlspecialchars($text,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    }
}
