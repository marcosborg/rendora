<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
$db=new HomeAlone\Database($argv[1]);$s=new HomeAlone\Scope($db,'company-lisbon',['id'=>'u-admin'],'ADMIN');
try{(new HomeAlone\Rentals($db))->createContract($s,['property_id'=>'p-graca','contact_ids'=>['co-beatriz'],'start_date'=>'2040-01-01','end_date'=>'2040-12-31','rent_cents'=>100000,'charges_cents'=>0,'deposit_cents'=>0,'due_day'=>1,'notes'=>'Concurrent test']);echo '200';}catch(HomeAlone\HttpError $e){echo $e->status;}
