<?php
declare(strict_types=1);
namespace HomeAlone;
final class Pdf {
    public static function render(string $title,string $company,array $paragraphs): string {
        $pdf=new \FPDF();$pdf->SetTitle(self::text($title));$pdf->SetAuthor(self::text($company));$pdf->SetMargins(22,22,22);$pdf->SetAutoPageBreak(true,24);$pdf->AddPage();$pdf->SetTextColor(28,113,97);$pdf->SetFont('Arial','B',12);$pdf->Cell(0,8,self::text($company),0,1);$pdf->SetFont('Arial','',9);$pdf->SetTextColor(110,128,118);$pdf->Cell(0,6,'RENDORA',0,1);$pdf->Ln(10);$pdf->SetFont('Arial','B',19);$pdf->SetTextColor(35,65,51);$pdf->MultiCell(0,9,self::text($title));$pdf->Ln(8);$pdf->SetDrawColor(215,227,220);$pdf->Line(22,$pdf->GetY(),188,$pdf->GetY());$pdf->Ln(9);$pdf->SetFont('Arial','',11);$pdf->SetTextColor(52,69,60);
        foreach($paragraphs as $paragraph){$pdf->MultiCell(0,6,self::text($paragraph));$pdf->Ln(5);}
        $pdf->Ln(4);$pdf->SetFont('Arial','',8);$pdf->SetTextColor(125,139,130);$pdf->MultiCell(0,5,self::text('Gerado em '.(new \DateTimeImmutable('now',new \DateTimeZone('Europe/Lisbon')))->format('d/m/Y H:i').' · Rendora'));
        return $pdf->Output('S');
    }
    private static function text(string $text): string { return iconv('UTF-8','windows-1252//TRANSLIT',$text)?:$text; }
}
