<?php
//Definistion LANG et Timezone
setlocale(LC_ALL, 'fr_FR');
date_default_timezone_set('Europe/Paris');
setlocale(LC_TIME, 'fr_FR.utf8','fra');// OK

require "/var/www/extranet/static/core_db.php";

//Connexion aux BDD
$dbcon_bill  = db_connect("bill");
$dbcon_local = db_connect("local");

//check des ARGS
if (!$argv[1]) die("Utilisation:\n $argv[0] id_mera num_facture type_facture forfait type_sortie\n\n Type Facture:\n  - 0 (Standard)\n  - fixe (Illimité fixe)\n  - fixemob (Illimité fixe & mobile)\n\n Forfait:\n  - 0 (pas de forfait)\n  - chiffre en heure du forfait\n\n Type_sortie:\n  - 0 (sortie standard)\n  - json (sortie JSON)\n");
if (!$argv[2]) die("Pas de numero de facture\n");

require_once '/var/www/extranet/static/core_db.php';
require_once '/var/www/extranet/static/PHPexcel_bill/PHPExcel.php';
require_once '/var/www/extranet/static/PHPexcel_bill/PHPExcel/IOFactory.php';

//Connexion aux BDD
$dbcon_bill  = db_connect("bill");
$dbcon_local = db_connect("local");

// Definition du type de facturation
$typ="Normal";
if (!empty($argv[3]) && $argv[3] == "fixe" ) $illimitefixe=1 && $typ="Illimité Fixe";
if (!empty($argv[3]) && $argv[3] == "fixemob" ) $illimite=1 && $typ="Illimité Fixe & Mobile";
if (!empty($argv[4])) $illimitefixe=1 && $typ="Illimité Fixe & forfait GSM $argv[4]H" && $forfait=$argv[4] && $forfaitcal=1;

// verifie si le client existe
$cust       = $argv[1];
$getsoc     = fsql_object("SELECT * FROM clients WHERE id_mera='$cust' LIMIT 1", $dbcon_bill, "0");
if (!$getsoc) die("ID mera invalide ou client non trouvé\n");

// Definition des variables
$bill       = $argv[2];
$datesql    = date('Y-m-d',strtotime("now"));
$start      = date("Y-m-d", strtotime("first day of previous month"));
$end        = date("Y-m-d", strtotime("last day of previous month"));
$startprint = date("d/m/Y", strtotime($start));
$endprint   = date("d/m/Y", strtotime($end));
$socname    = $getsoc->societe;
$dper       = date('d/m/Y',strtotime($start));
$endd       = strtotime($end);
$fper       = date('d/m/Y', strtotime('-1 day', $endd ));
$per        = date('d/m/Y',strtotime($start)). " to " .date('d/m/Y',strtotime($end));
$bill       = $argv[3];
$pay        = "=D12+$getsoc->pay";
$client     = "$getsoc->societe";
$addr1      = "$getsoc->addr1";
$addr2      = "$getsoc->addr2";
$addr3      = "$getsoc->addr3";
$vat        = "$getsoc->vat";
$base       = "$getsoc->base.xlsx";
$file       = "Facture $bill - $client.xls";
$table      = "";
$ld         = 21;
$page       = 1;

// Template
$objPHPExcel = PHPExcel_IOFactory::load("/var/www/extranet/static/template_facture/$base");
$objPHPExcel->setActiveSheetIndex(0)->setCellValue('J4', $bill);
$objPHPExcel->setActiveSheetIndex(0)->setCellValue('D12', $date);
$objPHPExcel->setActiveSheetIndex(0)->setCellValue('D14', $pay);
$objPHPExcel->setActiveSheetIndex(0)->setCellValue('H10', $client);
$objPHPExcel->setActiveSheetIndex(0)->setCellValue('H11', $addr1);
$objPHPExcel->setActiveSheetIndex(0)->setCellValue('H12', $addr2);
$objPHPExcel->setActiveSheetIndex(0)->setCellValue('H13', $addr3);
$objPHPExcel->setActiveSheetIndex(0)->setCellValue('I14', $contact);
$objPHPExcel->setActiveSheetIndex(0)->setCellValue('D22', $per);
$objPHPExcel->setActiveSheetIndex(0)->setCellValue('D60', $vat);

//Requete SQL
$result = fsql("SELECT c.area as zone,COUNT(*) as nbcalls,SUM(c.billsec)/60 as minutes,SUM(c.cost) as prix,a.area
                FROM cdr AS c
                LEFT JOIN area AS a ON c.area=a.id
                WHERE c.client = '$cust'
                AND c.cdr_date > '$start 00:00:00'
                AND c.cdr_date < '$end 23:59:59'
                GROUP BY c.area,c.rate
                ORDER BY a.area",
                $dbcon_bill, "0");

// fonction de generation sortie et calcul
function printcalc($farea,$fnbcalls,$fminutes,$frate,$abo) {
        global $table,$objPHPExcel,$page,$ld,$totcall,$totmin,$totprix,$totmob,$minmob,$prixmob;
        if (!$abo) {
                $ld++;
                if (!$frate) {
                        $iprix=0;
                        $frate=$prettyprix="Inclus";
                } else {
                        $iprix=$fminutes*$frate;
                        $prettyprix=round($iprix,2);
                }
                $prettyarea=preg_replace('/MOBILE/', 'MOB', $farea);
                $table  .="<tr>\n<td colspan=\"4\" style=\"border-right:0.1px solid black; border-top:0.1px solid black;\">$prettyarea</td>\n<td style=\"border-right:0.1px solid black; border-top:0.1px solid black;\">$fnbcalls</td>\n<td style=\"border-right:0.1px solid black; border-top:0.1px solid black;\">". round($fminutes) ."</td>\n<td style=\"border-right:0.1px solid black; border-top:0.1px solid black; text-align:right\">$prettyprix</td>\n</tr>\n";
                $objPHPExcel->setActiveSheetIndex($page)->setCellValue("B".$ld,$farea);
                $objPHPExcel->setActiveSheetIndex($page)->setCellValue("H".$ld,$fnbcalls);
                $objPHPExcel->setActiveSheetIndex($page)->setCellValue("I".$ld,round($fminutes));
                $objPHPExcel->setActiveSheetIndex($page)->setCellValue("J".$ld,$frate);
                $objPHPExcel->setActiveSheetIndex($page)->setCellValue("K".$ld,$prettyprix);
                $totcall = $totcall+$fnbcalls;
                $totmin  = $totmin+$fminutes;
                $totprix = $totprix+$iprix;
        } else {
                $iprix=$fminutes*$frate;
                $totcall = $totcall+$fnbcalls;
                $totmin  = $totmin+$fminutes;
                $totmob=$totmob+$fnbcalls;
                $minmob=$minmob+$fminutes;
                $prixmob=$prixmob+$iprix;
        }


}

// On verifie si on a des données de facturation
if (!mysql_num_rows($result)) die("Pas d'appel\n");

// Boucle sur les resultats de la requetes
while ($row = mysql_fetch_object($result)) {
        if ($ld == 122) { $ld=22; $page++; }
        $objPHPExcel->setActiveSheetIndex($page)->mergeCells("B".($ld).":G".$ld);
        $rate=round($row->prix/$row->minutes,3);
        $ratecheck=$row->prix/$row->minutes;

        // Numero Speciaux
        if ( $row->zone == 3182 || $row->zone == 5634 ) {
                // Prix speciaux NULL
                if ($ratecheck==0) {
                        printcalc($row->area,$row->nbcalls,$row->minutes,"0.090",0);
                } else {
                        // Autre prix speciaux
                        printcalc($row->area,$row->nbcalls,$row->minutes,$ratecheck/0.6,0);
                }
        } elseif ( !preg_match("#FRANCE#i", "'.$row->area.'") ) {
                        //international
                        printcalc($row->area,$row->nbcalls,$row->minutes,$ratecheck/0.9,0);
        } elseif ( $illimitefixe && $row->area == "FRANCE" ) {
                        //Illimité Fixe
                        printcalc($row->area,$row->nbcalls,$row->minutes,0,0);
        } elseif ( $illimite && ( $row->area == "FRANCE"  || preg_match("#FRANCE MOBILE#i", "'.$row->area.'"))) {
                        //illimité fixe & mobile
                        printcalc($row->area,$row->nbcalls,$row->minutes,0,0);
        } elseif ($forfait && preg_match("#FRANCE MOBILE#i", "'.$row->area.'")) {
                        //forfait GSM
                        printcalc($row->area,$row->nbcalls,$row->minutes,0,1);

        } else {
                        // tout le reste
                        printcalc($row->area,$row->nbcalls,$row->minutes,$row->prix/$row->minutes,0);
        } // fin speciaux

}// Fin While
for ($u=1;$u<=$page;$u++) {
        $objPHPExcel->setActiveSheetIndex($u)->getColumnDimension('B')->setWidth(11);
        $objPHPExcel->setActiveSheetIndex($u)->getColumnDimension('C')->setWidth(11);
        $objPHPExcel->setActiveSheetIndex($u)->getColumnDimension('D')->setWidth(11);
}

$objPHPExcel->setActiveSheetIndex(0);
$objPHPExcel->setActiveSheetIndex(0)->getColumnDimension('C')->setWidth(12);

//Calcul Forfait GSM
if ($forfaitcal) {
        $ld++;
        $forfait=$forfait/60;
        $printforfait="$forfait"."h";
        if ($forfait > $minmob) {
                printcalc("Forfait $printforfait FRANCE MOB",$totmob,$minmob,0,0);
        } else {
                $afactu=$minmob-$forfait;
                printcalc("Dépassement Forfait $printforfait FRANCE MOBILE",$totmob,$afactu,0.05,0);
        }
}

// Ecriture de l'excel
$objWriter = PHPExcel_IOFactory::createWriter($objPHPExcel, 'Excel5');
$factname="facture_".$bill."_".$datesql."_".$socname.".xls";
$objWriter->save("/data/facture_voip/$factname");
$addfact=fsql("INSERT INTO `extranet`.`voip_fact` (`date`, `file`, `prix`, `id_mera`, `periode`, `minute`, `nbcall`,`typ`,`num`) VALUES ('$datesql','$factname','$totprix','$cust','$per','$totmin','$totcall','$typ','$bill')",$dbcon_local,"0");

// Encodage JSON
if ($argv[5] == "json") {
        // JSON
        $total[] = array('prix' => round($totprix,2), 'calls' => $totcall, 'min' => round($totmin));
        $data[]  = array('data' => $table);
        echo json_encode(array('total' => $total, 'data' => $data));

} else {

        //Standard
        echo "$totprix;".round($totmin).";$totcall\n<table border=\"1\" style=\"border-style: solid; border-width: 0.3px 0.3px 0.3px 0.3px;\">\n<tr>\n<td colspan=\"4\">Destination</td>\n<td>Appel(s)</td>\n<td>Min(s)</td>\n<td>Coût €</td>\n</tr>\n$table</table>";
}
?>
