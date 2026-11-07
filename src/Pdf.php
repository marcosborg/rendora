<?php
declare(strict_types=1);
namespace HomeAlone;
final class Pdf {
    public static function contractText(array $contract,string $company,array $property,bool $english): string {
        $template=$contract['template'];
        if($english&&$template==='Contrato de arrendamento entre {{company}} e {{residents}}, relativo a {{property}}, com início em {{start}} e renda mensal de {{rent}}.'){
            $template='Rental agreement between {{company}} and {{residents}} for {{property}}, starting on {{start}}, with monthly rent of {{rent}}.';
        }
        return strtr($template,[
            '{{company}}'=>$company,'{{residents}}'=>implode(', ',array_column($contract['residents'],'name')),
            '{{property}}'=>$property['name'].' · '.$property['address'],'{{start}}'=>$contract['start_date'],
            '{{rent}}'=>Language::money($contract['rent_cents'],$english),
        ]);
    }

    public static function contract(array $contract,string $company,string $text,bool $english): string {
        $charges=Language::money($contract['charges_cents'],$english);
        $deposit=Language::money($contract['deposit_cents'],$english);
        $start=$contract['start_date'];
        $day=$contract['due_day'];
        $end=$contract['end_date']??($english?'Open-ended':'Sem termo');
        return self::render($english?'Rental agreement':'Contrato de arrendamento',$company,$english?[
            $text,"Start: $start | End: $end","Monthly charges: $charges | Expected deposit: $deposit",
            "Due on day $day of each month.",'Landlord or representative signature: __________________________',
            'Resident signature(s): __________________________',
        ]:[
            $text,"Início: $start | Fim: $end","Encargos mensais: $charges | Caução prevista: $deposit",
            "Vencimento: dia $day de cada mês.",'Assinatura do senhorio / representante: __________________________',
            'Assinatura do(s) morador(es): __________________________',
        ],$english);
    }

    public static function receipt(array $charge,string $company,bool $english): string {
        $rent=Language::money($charge['amount_cents'],$english);
        $paid=Language::money($charge['paid_cents'],$english);
        $balance=Language::money($charge['balance_cents'],$english);
        $period=$charge['period'];
        $due=$charge['due_date'];
        $lines=$english?[
            'NON-TAX DOCUMENT',"Period: $period | Due: $due","Rent amount: $rent",
            "Net amount received: $paid","Balance: $balance",'Transactions:',
        ]:[
            'DOCUMENTO NÃO FISCAL',"Período: $period | Vencimento: $due","Valor da renda: $rent",
            "Valor líquido recebido: $paid","Saldo: $balance",'Movimentos:',
        ];
        $labels=$english
            ?['PAYMENT'=>'Payment','REFUND'=>'Refund','ADJUSTMENT'=>'Adjustment','DISPUTE'=>'Dispute','DISPUTE_WON'=>'Dispute won']
            :['PAYMENT'=>'Pagamento','REFUND'=>'Reembolso','ADJUSTMENT'=>'Ajuste','DISPUTE'=>'Contestação','DISPUTE_WON'=>'Contestação ganha'];
        foreach($charge['movements'] as $payment){
            $lines[]=$payment['created_at'].' | '.$labels[$payment['kind']].' | '.Language::money($payment['amount_cents'],$english)
                .($payment['reason']?' — '.$payment['reason']:'');
        }
        $lines[]=$english?'This statement does not replace a tax receipt.':'Este comprovativo não substitui um recibo fiscal.';
        return self::render($english?'Payment statement':'Comprovativo de pagamentos',$company,$lines,$english);
    }

    public static function render(string $title,string $company,array $paragraphs,bool $english=false): string {
        $pdf=new \FPDF();$pdf->SetTitle(self::text($title));$pdf->SetAuthor(self::text($company));$pdf->SetMargins(22,22,22);$pdf->SetAutoPageBreak(true,24);$pdf->AddPage();$pdf->SetTextColor(28,113,97);$pdf->SetFont('Arial','B',12);$pdf->Cell(0,8,self::text($company),0,1);$pdf->SetFont('Arial','',9);$pdf->SetTextColor(110,128,118);$pdf->Cell(0,6,'RENDORA',0,1);$pdf->Ln(10);$pdf->SetFont('Arial','B',19);$pdf->SetTextColor(35,65,51);$pdf->MultiCell(0,9,self::text($title));$pdf->Ln(8);$pdf->SetDrawColor(215,227,220);$pdf->Line(22,$pdf->GetY(),188,$pdf->GetY());$pdf->Ln(9);$pdf->SetFont('Arial','',11);$pdf->SetTextColor(52,69,60);
        foreach($paragraphs as $paragraph){$pdf->MultiCell(0,6,self::text($paragraph));$pdf->Ln(5);}
        $pdf->Ln(4);$pdf->SetFont('Arial','',8);$pdf->SetTextColor(125,139,130);$pdf->MultiCell(0,5,self::text(($english?'Generated on ':'Gerado em ').(new \DateTimeImmutable('now',new \DateTimeZone('Europe/Lisbon')))->format('d/m/Y H:i').' · Rendora'));
        return $pdf->Output('S');
    }
    private static function text(string $text): string { return iconv('UTF-8','windows-1252//TRANSLIT',$text)?:$text; }
}
