<?

class ModelPay extends ModelLk  {


public function __construct(){

	parent::__construct();
	
	$this->lines=array(
		1=>0,
		2=>70,
		3=>10,
		4=>5,
		5=>5,
	);
	$this->sponProc=10 ;

	//require_once($this->modelsDir.'mMatrs.php');
 //	$this->mMatrs = new mMatrs();

;}


public function inpay($acc, $wallet, $password, $passwordAlter, $proezdnoi=false){

    $pmCompanyWallet=getVar('PAYEE_ACCOUNT');
    $pmPaymentId=getVar('PAYMENT_ID');
    $pmCost=getVar('PAYMENT_AMOUNT');
    $pmCurrency=getVar('PAYMENT_UNITS');
    $pmBatch=getVar('PAYMENT_BATCH_NUM');
    $pmUserWallet=getVar('PAYER_ACCOUNT');
    $pmTimeUnix=getVar('TIMESTAMPGMT');
    $pmHash=getVar('V2_HASH');

		$this->acc=$acc;
		$this->wallet=$wallet;
		$this->password=$password;
		$this->passwordAlter=$passwordAlter;


    if ($this->locServ) {$test=1; $this->test=$test;}

    if ($test) {
			$pmCompanyWallet='U8378938';
	    $pmPaymentId=180;
	    //$pmCost=5;
	    $pmCurrency='USD';
	    $pmBatch='90130854';
	    $pmUserWallet='U7010167';
	    $pmTimeUnix='1432240342';
	    $pmHash='49F8E762C6A2CA1134364B895C3EB168';
		;}





    //проверка id платежа
		$q = $this->db->genSelect('payments', array('id'=>$pmPaymentId));
		$DBPayment = $this->db->qSelectRow($q);

    if (!$DBPayment) {
			$report="Не найден id платежа ({$pmPaymentId}) в базе, который прислал PM" ;
			$this->setReport(false, $report);

      if (!$test) {	exit;}
		;}


		$type=$DBPayment['type'];
		$step=$DBPayment['step'];
		$cost=$DBPayment['cost'];

     //проверка суммы
   //из таблицы оплат не берем, там чисто символическая
    $params=array(
		'type'=>$type,
		'step'=>$step,
		);
		$q = $this->db->genSelect('costs', $params);
		$costDB = $this->db->qSelectField($q, 'cost');

    if ($cost!=$pmCost) {
			$report="Присланная с PM сумма  ({$pmCost}) не верна." ;
			$this->setReport($pmPaymentId, $report);
	 	 	 if (!$test) {	exit;}
		;}




     //проверка хешей
		$md5pwd = strtoupper( md5($passwordAlter));
		$string=
		$pmPaymentId.':'.$pmCompanyWallet.':'.
		$pmCost.':'.$pmCurrency.':'.
		$pmBatch.':'.
		$pmUserWallet.':'.$md5pwd.':'.
		$pmTimeUnix;

		$pmHashResult = strtoupper( md5($string) );




		
		if( $pmHash != $pmHashResult ){
			$report="Хеши не совпадают" ;
			$this->setReport($pmPaymentId, $report);
			if (!$test) {	exit;}
		}
		
		//с оплатой отлично, активируем платеж
		$q = $this->db->genUpdate('payments', array('payed'=>1), false, $pmPaymentId);
    mysql_query($q);

 
    $userId = $DBPayment['userId'];

		$this->MATR_ALG($userId, $type, $step, $cost, $pmPaymentId);

;}


private function MATR_ALG($userId, $type, $step, $cost, $pmPaymentId, $incomeAdd=false){
	
	$systId=$this->getSystUserId();
	$this->incomeAdd=$incomeAdd;
	

 	

		
		if ($type=='lk') {
			$this->insertMatr($userId, 0, 'lk', 'lk', false, false, $cost);
			
			$this->addIncomeMess($userId, $systId, 'lk', $cost, $cost);
		;}


		if ($type==5) {
			$this->openMatrix5($systId, $userId, $type, $step, $cost);
		;}

		if ($type==7) {
			$this->openMatrix7M($systId, $userId, $type, $step, $cost, $pmPaymentId);
		;}



     //ЗАНОСИМ В ИСТОРИЮ
		$dopInf="выплаты: {$this->chainWhomPay}, спонс: {$this->chainSpons}, матр: {$this->chainMatrs}, вставленные м {$this->chainMatrsIns} ";
		$this->addHistory($userId, "открытие $type $step", $this->whomPay, $this->whomPurpose, $dopInf, $cost, $autopay) ;

;}



private function openMatrix7M($systId, $userId, $type, $step, $cost, $pmPaymentId){
	$ms = $this->getMatrixSpon($userId, $type, $step);

	if ($ms) {

		$sponId = $ms['matrSponId'];
		$matrIdPar = $ms['matrId'];
		
		$this->chainSpons.= $sponId.'_';
		$this->chainMatrs.= $matrIdPar.'_';


		//выясняем куда мне вставать к спонсору в матрицу, и встаем
		$branchs[1]=$this->getMatrsChilds($matrIdPar, $type, $step);
		$branchId1=$branchs[1][1]['id'];
		$branchId2=$branchs[1][2]['id'];
		$branchs[2][1]=$this->getMatrsChilds($branchId1, $type, $step);
		$branchs[2][2]=$this->getMatrsChilds($branchId2, $type, $step);

/* 
							      () $branchs[1]
									/   \
$branchs[2][1]  ()    () $branchs[2][2]
							 / \	 / \
						 ()  ()	()  ()
*/
    if (count($branchs[1])==2) {//полностью занята 1 линия

      $turn[2][1]=count($branchs[2][1])+1;
      $turn[2][2]=count($branchs[2][2])+1;

			switch (count($branchs[2][1])) {

				case 0:   //2-1 пустая, под нее и становимся
					$parId=$branchId1;
					$turn_=$turn[2][1];
				break;
				
//если в 2-1 ветке один есть, то возможно распределяем во вторую
				case 1:
        if (count($branchs[2][2])==0) {//во второцй никого? становимся под нее
					$parId=$branchId2;
					$turn_=$turn[2][2];
				;} else {//во второй 1 есть? значит идем в первую
					$parId=$branchId1;
					$turn_=$turn[2][1];
				;}
				break;


				case 2://2-1 занята полностью, встаем во вторую
					$parId=$branchId2;
					$turn_=$turn[2][2];
				break;
				}


		;} else {//1 линия занята не полностью

			//встаем в матрицу матричного спонсора по очереди
			$parId=$matrIdPar;
			$turn_=count($branchs[1])+1;

		;}


		$newMatrId=$this->insertMatr($userId, $parId, $type, $step, $turn_);


		$countAll=count($branchs[1])+count($branchs[2][1])+count($branchs[2][2])+1;

		if ($countAll>=6) {
			//закрываем матрицу спонсора
			$q = $this->db->genUpdate('matrixes', array('active'=>0), false, $matrIdPar);
			mysql_query($q);
			

//автореинвест
			if ($systId==$sponId) {	
				$this->openMatrix7M($systId, $sponId, $type, $step, $cost, $pmPaymentId);} ;

			;}


		$this->distribMoney($systId, $userId, $newMatrId, $sponId, $cost, $pmPaymentId);

	 $this->whomPurpose='постановка в матрицу к спонсору';

		


	;} else {
		//матричный не найден, открываем матрицу без спонсора
			$this->insertMatr($userId, 0, $type, $step);
			
			$this->whomPay='syst';
			$this->whomPurpose="Открытие без спонсора";
			
			$this->updIncome($systId, $type, $cost);
			$this->addIncomeMess($userId, $systId, $type, $cost, $cost, false, $this->whomPurpose);
		
		
	;}


;}


private function partCost($proc, $cost){
		$chast=$proc/100;
		$chastCost=$chast*$cost;
	 //	$chastCost=round($chastCost, 2, PHP_ROUND_HALF_DOWN);
		return $chastCost;
;}

//distribMoney($matrId, $sponId, $cost, $pmPaymentId)
private function distribMoney($systId, $fromId, $matrId, $sponId, $cost, $pmPaymentId){
	
	$lines=	$this->lines;
	$sponProc = $this->sponProc ;

	//перечисляем деньги личному спонсору
		$chastCost = $this->partCost($sponProc, $cost) ;
		if ($sponId AND $sponProc) {
			$autopay=$this->autoPay($sponId, $chastCost, $pmPaymentId);
			$this->updIncome($sponId, 7, $chastCost, 'main');
			$this->addIncomeMess($fromId, $sponId, 7, $cost, $chastCost, 'lich');
		;}
			$this->chainWhomPay.= $sponId.'(spon):'.$chastCost.':'.$autopay.'_';

	 ;


	foreach($lines as $line=>$proc){


   //начинаем с моей матрицы
			$params=array(
			 'id'=>$matrId,
			);
			$q = $this->db->genSelect('matrixes', $params);
			$matrId = $this->db->qSelectField($q, 'parentId');

			$chastCost=$this->partCost($proc, $cost);

		if ($matrId) {

      $whomPay =$this->getUserIdByMatr($matrId);

		//перечисляем деньги по алгоритму всем спонсорам
    	if ($chastCost) {
    		$autopay=$this->autoPay($whomPay, $chastCost, $pmPaymentId);
				$this->updIncome($whomPay, 7, $chastCost,(($line>2) ?  ("bonus") :  ("main")));
				$this->addIncomeMess($fromId, $whomPay, 7, $cost, $chastCost, $line);
			;}
			$this->chainWhomPay.= $whomPay.':'.$chastCost.':'.$autopay.'_';

     // print_r($matrId); print_r("<br/>");

		;} else{
			
				$this->updIncome($systId, 7, $chastCost,(($line>2) ?  ("bonus") :  ("main")));
				$this->addIncomeMess($fromId, $systId, $type, $cost, $chastCost, $line);

			$this->chainWhomPay.= 'syst_';

		}

	;}

;}



public function getUserIdByMatr($matrId){

		$params=array(
     'id'=>$matrId,
		);
		$q = $this->db->genSelect('matrixes', $params);
		$userId = $this->db->qSelectField($q, 'userId');

		return $userId;
;}

public function getMatrsChilds($matrId, $type, $step){// 2 последних - убрать потом

    $matrixes_=array();

    if ($matrId) {
			$params=array(
	     'parentId'=>$matrId,
	     'type'=>$type,
	     'step'=>$step,
			);
			$q = $this->db->genSelect('matrixes', $params, false, false, 'turn');
			$matrixes = $this->db->qSelectList($q);

			foreach($matrixes as $i=>$matrix){$matrixes_[($i+1)]=$matrix;;}
    ;}

		return $matrixes_;
;}


private function updIncome($userId, $type, $cost, $mode='main'){
	
if ($this->incomeAdd) {return false;} ;

	if ($userId) {

		$userId=$this->db->fv($userId);
		$cost=$this->db->fv($cost);

		$q ="UPDATE ##users SET ";

    if ($type==7) {

			if ($mode=='main') {
				$q.="main7Income=main7Income + {$cost}";
			;} elseif ($mode=='bonus') {
				$q.="bonus7Income=bonus7Income + {$cost}";
			;}

		;} else {

				$q.="5Income=5Income + {$cost}";

		;}

		$q.="WHERE id={$userId}";


		$q=$this->db->qFormat($q);
   
		mysql_query($q);

	;} 

;}


private function autoPay($whomPay, $cost, $paymentId){

if ($this->incomeAdd){
	
	$report='проездной';
	
}else{
	 	
	$koefKomissiya=0.9950248756;
	$cost=$cost*$koefKomissiya;
	$cost=round($cost, 2, PHP_ROUND_HALF_DOWN);



	if ($whomPay!='syst') {

		$q = $this->db->genSelect('users', array('id'=>$whomPay,));
		$pmWallet = $this->db->qSelectField($q, 'pmWallet');

	  if ($pmWallet) {

		  $q = "https://perfectmoney.is/acct/confirm.asp?AccountID={$this->acc}&PassPhrase={$this->passwordAlter}&Payer_Account={$this->wallet}&Payee_Account={$pmWallet}&Amount={$cost}&PAY_IN=1&PAYMENT_ID={$paymentId}";

		if (!$this->test) {
			$reportPayPM=file_get_contents($q);
		;}

			$this->systReport('reportPayPM', $reportPayPM);
			$report='отправлено';

		;} else {
			$report='не найден кошелек';
		;}

	;} else {

		$report='системе';

	;}

  
;}


		return $report;
;}

public function addHistory($userBegin, $purpBegin, $userEnd, $purpEnd, $dopInf, $cost, $autoPay){
	
//if ($this->incomeAdd) {return false;} ;

		$params=array(
				'userBegin'=>$userBegin,
				'purpBegin'=>$purpBegin,
				'userEnd'=>$userEnd,
				'purpEnd'=>$purpEnd,
				'dopInf'=>$dopInf,
				'cost'=>$cost,
				'autoPay'=>$autoPay,
			);
		$q = $this->db->genInsert('hystory', $params);
		mysql_query($q);

;}


private function openMatrix5($systId, $userId, $type, $step, $cost){


  //определение матричного спонсора
	$ms = $this->getMatrixSpon($userId, $type, $step);

	if ($ms) {

		$matrIdPar = $ms['matrId'];
		$sponId = $ms['matrSponId'];
		
		$this->chainSpons.= $sponId.'_';
		$this->chainMatrs.= $matrIdPar.'_';


		//узнаем количество рефералов в матрице спонсора 1я линия
		//чтобы определить под каким порядком вставать в нее
		$q = $this->db->genSelect('matrixes', array('parentId'=>$matrIdPar) );
		$childMatrs = $this->db->qSelectList($q);
		$turn=count($childMatrs)+1;
     //print_r($matrIdPar); print_r("<br/>");  exit;

		//открываем матрицу
   //встаем в матрицу матричного спонсора по очереди
		$this->insertMatr($userId, $matrIdPar, $type, $step, $turn);



		//теперь ПЕРЕХОДИМ К СПОНСОРУ
		//проверка на закрытие и открытие новой
		if ($turn>=4) {

			//закрываем текущую
			$q = $this->db->genUpdate('matrixes', array('active'=>0), false, $matrIdPar);
			mysql_query($q);

			//открываем новую, автореинвест
			$this->openMatrix5($systId, $sponId, $type, $step, $cost);


		;} else {
			
			$autopay=$this->autoPay($sponId, $cost, $pmPaymentId);
			$this->updIncome($sponId, $type, $cost);
			$this->addIncomeMess($userId, $sponId, $type, $cost, $cost);
			
			$this->whomPay=$sponId;
			$this->whomPurpose='постановка в матрицу';

		;}




	;} else { //матричный не найден, открываем матрицу без спонсора
			
		$this->insertMatr($userId, 0, $type, $step);
		
		$this->whomPay='syst';
		$this->whomPurpose='Открытие без спонсора';
		
		$this->updIncome($systId, $type, $cost);
		$this->addIncomeMess($userId, $systId, $type, $cost, $cost, false, $this->whomPurpose);
		

	;}



;}


private function insertMatr($userId, $parentId, $type, $step, $turn=false, $active=false, $cost=false){


	if ($active!==0) {$active=1;}


		$params=array(
				'userId'=>$userId,
				'parentId'=>$parentId,
				'type'=>$type,
				'step'=>$step,
				'cost'=>$cost,
				'turn'=>$turn,
				'active'=>$active,
			);
		$q = $this->db->genInsert('matrixes', $params);
		mysql_query($q);
		$insId=mysql_insert_id();
		$this->chainMatrsIns.= $insId.'_';
		return $insId;
	}


  //получить матрицу матричного спонсора
public function getMatrixSpon($userId, $type, $step){

		$sponId = $this->getSponId($userId);

   if ($sponId) {
    //берем его самую последнюю матрицу открытую
		$params=array(
			'userId'=>$sponId,
			'type'=>$type,
			'step'=>$step,
			'active'=>1,
		);
		 $q = $this->db->genSelect('matrixes', $params, false, false, 'id DESC');
		 $matrix = $this->db->qSelectRow($q);

		if ($matrix) {

			$params=array(
				'matrId'=>$matrix['id'],
				'matrSponId'=>$sponId,
			);
       return $params;

		;} else {

	    return $this->getMatrixSpon($sponId, $type, $step);

		;}

	 ;} else {
      return false;
	 ;}

	;}




public function setReport($pmPaymentId=false, $report, $err=1){

		if ($pmPaymentId) {
			$q = $this->db->genUpdate('payments', array('err'=>$err, 'report'=>$report, ), false, $pmPaymentId);
		;} else {
			$q = $this->db->genInsert('payments', array('err'=>$err, 'report'=>$report, ));
		;}
     mysql_query($q)

;}


public function incomeActions($fromId, $toId, $typeMatr, $cost, $chastCost, $line=false, $miniMess=false){
	
;}


public function addIncomeMess($fromId, $toId, $typeMatr, $cost, $chastCost, $line=false, $miniMess=false){		
	
if ($this->incomeAdd) {return false;} ;

	$lines=	$this->lines;
	$lines['lich']=$this->sponProc;

	if ($typeMatr==5) {$typeMatr='START';};
	if ($typeMatr==7) {$typeMatr='MAXIMUM';};		
	if ($typeMatr=='lk') {$typeMatr='Личного Кабинета';};		
	

	if ($line) {
		$lineTxt.= "({$lines[$line]}%";
		if ($line=='lich') {
			$lineTxt.= ", личный рефереал";
		;}else{
			$lineTxt.= ", линия $line";
		;} ;
			$lineTxt.= ")";
	;}	
	
	$mess="{$chastCost}$ за открытие {$typeMatr} {$cost}$ {$lineTxt}";

	if ($miniMess) {$mess.=". $miniMess";}

	$params=array(
		'toId'=>$toId,
		'fromId'=>$fromId,
		'mess'=>$mess,
		'type'=>'income',
	);
	$q = $this->db->genInsert('messes', $params);
	mysql_query($q);
;}



public function proezdnoi($beginProezdnoi){

	$accessMinutes = 20;

	$beginUnix = strtotime($beginProezdnoi);
	$accessUnix = $accessMinutes*60;		
	if (time()-$accessUnix < $beginUnix) {return 1;};

;}





}



?>