<?php
declare(strict_types=1);
namespace HomeAlone;

final class CommercialPage {
    public static function render(bool $english, string $state=''): string {
        $copy=static fn(string $pt,string $en):string=>$english?$en:$pt;
        $plansTitle=$copy('Planos para profissionais','Plans for professionals');
        $planNote=$copy('Preços indicativos, sem IVA, por empresa. Subscrições ainda indisponíveis.','Indicative prices per company, excluding VAT. Subscriptions are not yet available.');
        $common=$copy('Ambos incluem gestão de contratos, acompanhamento de rendas e despesas, manutenção, mensagens e portais para moradores e proprietários.','Both include rental agreements, rent and expense tracking, maintenance, messaging, and resident and owner portals.');
        $essential=$copy('Essencial','Essential');
        $management=$copy('Gestão','Management');
        $month=$copy('/mês','/month');
        $year=$copy('/ano','/year');
        $essentialLimit=$copy('Até 10 unidades e 2 membros da equipa.','Up to 10 rental units and 2 team members.');
        $managementLimit=$copy('Até 40 unidades e 5 membros da equipa.','Up to 40 rental units and 5 team members.');
        $title=$copy('Pedir uma demonstração','Request a demo');
        $intro=$copy('Conta-nos quem és e quantas unidades geres. Entramos em contacto para combinar uma demonstração.','Tell us who you are and how many units you manage. We will get in touch to arrange a demo.');
        $action=$english?PublicPages::FEATURES_EN:PublicPages::FEATURES_PT;
        $privacy=$copy('/privacidade','/en/privacy');
        $notice=$copy('A Netlook usa estes dados para responder ao teu pedido.','Netlook uses these details to respond to your request.');
        $privacyLabel=$copy('Política de privacidade','Privacy policy');
        $name=$copy('Nome','Name');
        $email=$copy('Email profissional','Work email');
        $company=$copy('Empresa ou atividade profissional','Company or professional activity');
        $units=$copy('Unidades geridas','Rental units managed');
        $choose=$copy('Selecciona uma opção','Select an option');
        $send=$copy('Enviar pedido','Send request');
        $status=match($state){
            'received'=>'<p class="form-status" role="status">'.$copy('Pedido recebido. Entraremos em contacto por email.','Request received. We will get in touch by email.').'</p>',
            'invalid'=>'<p class="form-status" role="alert">'.$copy('Revê os campos e volta a enviar o pedido.','Check the fields and submit the request again.').'</p>',
            'limited'=>'<p class="form-status" role="alert">'.$copy('Foram feitos demasiados pedidos. Tenta mais tarde.','Too many requests. Please try again later.').'</p>',
            default=>'',
        };

        return <<<HTML
<section class="commercial-section" id="planos" aria-labelledby="plans-title">
    <h2 id="plans-title">$plansTitle</h2><p>$planNote</p><p>$common</p>
    <div class="plan-grid">
        <article><h3>$essential</h3><p><strong>19 €</strong> $month · 190 € $year</p><p>$essentialLimit</p></article>
        <article><h3>$management</h3><p><strong>39 €</strong> $month · 390 € $year</p><p>$managementLimit</p></article>
    </div>
</section>
<section class="commercial-section" id="demonstracao" aria-labelledby="request-title">
    <h2 id="request-title">$title</h2><p>$intro</p>$status
    <form class="demo-enquiry" action="$action#demonstracao" method="post">
        <label>$name<input name="name" autocomplete="name" required minlength="2" maxlength="120"></label>
        <label>$email<input name="email" type="email" autocomplete="email" required maxlength="254"></label>
        <label>$company<input name="company" autocomplete="organization" required minlength="2" maxlength="150"></label>
        <label>$units<select name="units" required><option value="">$choose</option><option value="1-10">1–10</option><option value="11-40">11–40</option><option value="41+">41+</option></select></label>
        <div hidden><label>Website<input name="website" tabindex="-1" autocomplete="off"></label></div>
        <p>$notice <a href="$privacy">$privacyLabel</a></p>
        <button class="cta" type="submit">$send</button>
    </form>
</section>
HTML;
    }
}
