#!/usr/bin/php
<?php
/**
 * ELB Internal Route53 Updater
 * @author Mandar Dhasal (mandar.dhasal@myglamm.com)
 * @version 1.0
 */

/**
* 
*/
    
class UpdateRoute53
{
    var $elbNetworkDescription = 'ELB xyz';

    var $route53InternalHostedZoneId = 'xyzxyzxyz';

    var $route53InternalRecordName = 'api-internal.xyz.io.'; //ending with dot

    var $snsTargetArn = 'arn:aws:sns:xyz'; 

    var $config = array(
        'region' => 'ap-south-1'
    );

    //var $vpcId = '<vpc-id>';

    var $markOldDB = __DIR__.'/database/markOldDB.json'; 

    /**
     *  Contstructor
     *
    */
    function __construct(){
        echo "\n--------------------------Update Route53 with ELB internal IP---------------------------------\n";
        echo "\n".date('d-M-Y H:i:s')."\n";
        $this->checkDB();
        $this->initialize();
    }


    /**
     *  Contstructor
     *
    */
    Private function initialize(){

        require dirname(__FILE__) . '/aws-autoloader.php';
        //use Aws\Ec2\Ec2Client; //use Aws\Route53\Route53Client;
        
        //get ELB Info
        $ec2Client = new Aws\Ec2\Ec2Client( array_merge($this->config, ['version' => '2016-11-15']) ) ;
        $elbInterfaces = $ec2Client->describeNetworkInterfaces(array(
            'Filters' => array(
            array(
                    'Name' => 'description',
                    'Values' => [ $this->elbNetworkDescription ]
            )/*,
            array(
                    'Name' => 'vpc-id',
                    'Values' => [ $this->vpcId ]
                )*/
            )
        ));

        $this->elbIps = array();
        foreach ($elbInterfaces['NetworkInterfaces'] as $elbInterface) {
            $this->elbIps[] = $elbInterface['PrivateIpAddress'];
        }

        //get Route53 Info
        $this->route53Client = new Aws\Route53\Route53Client( array_merge($this->config, ['version' => '2013-04-01']) );
        $records = $this->route53Client->listResourceRecordSets(array(
            'HostedZoneId' => $this->route53InternalHostedZoneId,
            'StartRecordName' => $this->route53InternalRecordName
        ));

        $this->route53RecordSets = array();
        foreach ($records['ResourceRecordSets'] as $record) {
            if( $record['Name'] == $this->route53InternalRecordName ){
                $this->route53RecordSets[] = $record;
            }
        }

        //SNS Client initializing
        $this->snsClient = new Aws\Sns\SnsClient(array_merge($this->config, ['version' => '2010-03-31']) );

    }



    Private function checkDB(){

        //echo $this->markOldDB; exit;

        if(!is_writeable( dirname( $this->markOldDB ) )){
           
            echo "\nERROR: DB directory not writable.\n";
            exit;
           
        }


        if(!file_exists($this->markOldDB)){
            echo "\nCreating DB file.\n";
            touch($this->markOldDB) or die('ERROR: Could not write DB file.');
            chmod($this->markOldDB, 0660);
        }
        
        echo "\nConnected to DB.\n";
    }


    Public function update(){

        echo "\nELB internal IPs.\n";
        print_r($this->elbIps);

        $route53Ips = $this->getRoute53Ips();
        echo "\nRoute53 IPs.\n";
        print_r($route53Ips);

        // CASE - 1  if route53 dosen't have the domain mapping .. add it
        if( empty($route53Ips) ){ 

            echo "\nRecord set dose not exist in Route53. Adding new record set for first time.\n";
            $result = $this->changeIpsRoute53($this->elbIps, 'CREATE');

            //add to markold
            $this->setMarkOld($this->elbIps);
            return;
        
        }

        $this->removeOldRecordsDB();


        echo "\nChecking for changes.\n";

        $markedOldIps = $this->getMarkOld();   

        echo "\nMarked Old ips.\n";
        print_r($markedOldIps);

        if(  ( count($this->elbIps) == count($route53Ips) ) && !$this->array_equal($this->elbIps, $route53Ips) ){

            $diffIps = array_diff($this->elbIps, $route53Ips);

        }else{ 

            $diffIps = array_diff($this->elbIps, $route53Ips, $markedOldIps);   
        }

        if(empty($diffIps)){

            echo "\nNo changes found.\n";
        
        }else{

            echo "\nChanges found. Updating Route53.\n";
            print_r($diffIps);

            $this->changeIpsRoute53($diffIps,'UPSERT');
            $newMarkOld = array_merge($diffIps, $markedOldIps);
            $newMarkOld = array_values( array_unique($newMarkOld) );
            $this->setMarkOld($newMarkOld);

        }


       

        $this->removeOldRecordsDB();
        $this->removeOldRecordsRoute53();

    }

    Private function array_equal($a, $b) {
        return (
             is_array($a) 
             && is_array($b) 
             && count($a) == count($b) 
             && array_diff($a, $b) === array_diff($b, $a)
        );
    }

    Private function removeOldRecordsDB(){

        echo "\nChecking for invalid Ips in DB.\n";

        $markedOldIps = $this->getMarkOld();
        $diff = array_diff($markedOldIps, $this->elbIps);
        if(!empty($diff)){
            echo "\nRemoving from old marked Ips.\n";
            print_r($diff);

            $newMarkOld = array_diff($markedOldIps, $diff);
            $newMarkOld = array_values( array_unique($newMarkOld) );
            $this->setMarkOld($newMarkOld);
        }else{
            echo "\nNo invalid IP found in mark old DB.\n";
        }
    }




    Private function removeOldRecordsRoute53(){

        echo "\nChecking for invalid Ips in Route53.\n";


        $diff = array_diff($this->getRoute53Ips(), $this->elbIps);
        if(!empty($diff)){
            echo "\nRemoving from Route53 Ips.\n";
            print_r($diff);
            $this->changeIpsRoute53($diff,'DELETE');
            
        }else{
            echo "\nNo invalid IP found in Route53.\n";
        }
    }


    Private function setMarkOld($data=""){
     
       return file_put_contents($this->markOldDB, json_encode($data));

    }

    Private function getMarkOld(){

        $ips = json_decode( file_get_contents($this->markOldDB) );
        return is_array($ips) ? $ips : array(); 
        
    }


    Private function getRoute53Ips(){
        $recordSetsIps = array();
        foreach ( $this->route53RecordSets as $record ) {
            $recordSetsIps[] = $record['ResourceRecords'][0]['Value'];
        }
        return $recordSetsIps;
    }

    Private function getRecordSetIds(){
        $recordSetIds = array();
        foreach ( $this->route53RecordSets as $record ) {
            $recordSetIds[$record['ResourceRecords'][0]['Value']] = $record['SetIdentifier'];
        }
        return $recordSetIds;
    }


    Private function changeIpsRoute53($ips = array(), $action=''){

        if( empty($ips) ){
            echo "\nERROR: Invalid IP address passed.\n";
            return false;
        }

        if( empty($action) ){
            echo "\nERROR: changeIpsRoute53: action not passed.\n";
            return false;
        }

        switch ($action) {
            case 'CREATE':
                
                    $changes = array();
                    $set = 1;
                    foreach ($ips as $ip) {
                    
                        $setid = 'set-api-internal-'.$set++.'-'.time();
                        $changes[] = array(
                                
                                'Action' => 'CREATE',
                                'ResourceRecordSet' => array(
                                    'Name' => $this->route53InternalRecordName,
                                    'SetIdentifier' => $setid,
                                    'TTL' => 2,
                                    'Type' => 'A',
                                    'ResourceRecords' => array(['Value' => $ip]),
                                    'Weight' => 100
                                )
                            );
                    }

                break;

            case 'UPSERT':
                $cnt = 0;

                $changes = array();
                
                foreach ($ips as $ip) {

                    if(isset($this->route53RecordSets[$cnt]['SetIdentifier'])){

                        $setid =  $this->route53RecordSets[$cnt]['SetIdentifier'];
                        $this->route53RecordSets[$cnt]['ResourceRecords'][0]['Value'] = $ip;

                    }else{

                        $setid = 'set-api-internal-'.$cnt.'-'.time();
                    
                    }
                    $cnt++;
                    $changes[] = array(
                            
                            'Action' => 'UPSERT',
                            'ResourceRecordSet' => array(
                                'Name' => $this->route53InternalRecordName,
                                'SetIdentifier'=> $setid,
                                'TTL' => 2,
                                'Type' => 'A',
                                'ResourceRecords' => array(['Value' => $ip]),
                                'Weight'=> 100
                            )
                        );
                }
                
                break;

                case 'DELETE':
                        $recordSetIds = $this->getRecordSetIds();
                        foreach ($ips as $ip) {
        
                            $changes[] = array(
                                    
                                    'Action' => 'DELETE',
                                    'ResourceRecordSet' => array(
                                        'Name' => $this->route53InternalRecordName,
                                        'SetIdentifier'=> $recordSetIds[$ip],
                                        'Type' => 'A',
                                        'TTL' => 2,
                                        'ResourceRecords' => array(['Value' => $ip]),
                                        'Weight'=> 100
                                    )
                                );
                        }
                    break;
            
            default:
                echo "\nERROR: changeIpsRoute53: invalid action.\n";
                return;
                break;
        }
        



       
        $result = $this->route53Client->changeResourceRecordSets(array(
        'HostedZoneId' => $this->route53InternalHostedZoneId,
        'ChangeBatch' => array(
                        'Comment' => 'ELB Private IPs update',
                        'Changes' => $changes
                        )
                )
        );

        // echo "\n"; var_dump($result); echo "\n";

        echo "\nChanges to Route53 is successful.\n";

        $snsData['msg'] = 'Action => '. $action.'  Data => '.json_encode($ips);
        $snsData['subject'] = 'Updated Route53. '. $action;
        $this->sendSNS($snsData);


        return $result;
    }




    Private function sendSNS( $snsData = array() ){

         if( empty($snsData['msg']) || empty($snsData['subject']) ){
            echo "\nERROR: SNS: no msg or subject provided.\n";
            return;
         }

         $result = $this->snsClient->publish([
            'Message' => $snsData['msg'], // REQUIRED
            /*'MessageAttributes' => [
                '<String>' => [
                    'BinaryValue' => <string || resource || Psr\Http\Message\StreamInterface>,
                    'DataType' => '<string>', // REQUIRED
                    'StringValue' => '<string>',
                ],
                // ...
            ],*/
            //'MessageStructure' => '<string>',
            //'PhoneNumber' => '<string>',
            'Subject' => $snsData['subject'],
            //'TargetArn' => '<string>',
            'TopicArn' => $this->snsTargetArn
        ]);
        
        echo "\nSNS sent successfully.\n";
    }




    function __destruct()
    {   
        echo "\n----------------------------------------------END---------------------------------------------\n";
        
    }
}


$UpdateRoute53 = new UpdateRoute53();
$UpdateRoute53->update();