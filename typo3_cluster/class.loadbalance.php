<?
/***************************************************************
*  Copyright notice
*
*  (c) 2008 Saverio Vigni <s.vigni@hor-net.com>, Federico Stendardi <stenda85@alice.it>
*  All rights reserved
*
*  This script is part of the TYPO3 project. The TYPO3 project is
*  free software; you can redistribute it and/or modify
*  it under the terms of the GNU General Public License as published by
*  the Free Software Foundation; either version 2 of the License, or
*  (at your option) any later version.
*
*  The GNU General Public License can be found at
*  http://www.gnu.org/copyleft/gpl.html.
*  A copy is found in the textfile GPL.txt and important notices to the license
*  from the author is found in LICENSE.txt distributed with these scripts.
*
*
*  This script is distributed in the hope that it will be useful,
*  but WITHOUT ANY WARRANTY; without even the implied warranty of
*  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
*  GNU General Public License for more details.
*
*  This copyright notice MUST APPEAR in all copies of the script!
***************************************************************/

    class user_typo3_cluster_loadbalance{

	var $_memcache = '';


	function setEnv(){
	    
	    //if no servers ar define in localconf, just skip the load balancing!ù
	    
	    if(!is_array($GLOBALS["TYPO3_CONF_VARS"]["SYS"]["nodes"])){
	      return;
	    }

	    if(strstr($_SERVER['REQUEST_URI'],'/typo3')){
          $_GET['typo3_cluster_execute']=1;
	      return;
	    }
	    if($_POST['typo3_cluster_execute'] || $_GET['typo3_cluster_execute']){	      
	      $_SERVER["HTTP_HOST"]=$_POST['typo3_cluster_name'];	      
	      $_SERVER["SERVER_NAME"]=$_POST['typo3_cluster_name'];
	      if($_GET['typo3_cluster_nodes']){
              $_POST['typo3_cluster_nodes']=$_GET['typo3_cluster_nodes'];
	      }
	      $_POST['typo3_cluster_nodes']=unserialize(stripslashes($_POST['typo3_cluster_nodes']));
	      //print_r($_POST['typo3_cluster_nodes']);
	      if(is_array($_POST['typo3_cluster_nodes'])){
            $GLOBALS["TYPO3_CONF_VARS"]["SYS"]["nodes"]=$_POST['typo3_cluster_nodes'];
	      }
	    } 
	}

	function main(){
//	    return;
	    //if no servers ar define in localconf, just skip the load balancing!
	    if(!is_array($GLOBALS["TYPO3_CONF_VARS"]["SYS"]["nodes"])){
	      return;
	    }
	    if($_POST['typo3_cluster_execute'] || $_GET['typo3_cluster_execute'] || strstr($_SERVER['REQUEST_URI'],'/typo3')){
	      return;
	    }

	    $timeout = 0.5;
	    if($GLOBALS["TYPO3_CONF_VARS"]["SYS"]['cluster_timeout']){
	      $timeout = $GLOBALS["TYPO3_CONF_VARS"]["SYS"]['cluster_timeout'];
	    }

	    $postData = file_get_contents("php://input");	    
	    
	    //$opts = array(
	    //  'http'=>array(
		//  'method'=>"GET",
		//  'timeout'=>$timeout
	    //  )
	    //);
         
        //print_r($_COOKIE);
        //check if a server cookie is set, if so that means that we need to keep the session on that server!
        if($_COOKIE['typo3_cluster_server']){
            $statArray[$_COOKIE['typo3_cluster_server']]=0;
        }else if($_GET['typo3_cluster_server']){
            $statArray[$_GET['typo3_cluster_server']]=0;
        }
	  //print_r($statArray);
	    //$statArray=$this->_memcache->get('__statArray');
	    if(!is_array($statArray) && is_array($GLOBALS["TYPO3_CONF_VARS"]["SYS"]["nodes"])){
            
            foreach($GLOBALS["TYPO3_CONF_VARS"]["SYS"]["nodes"] as $key=>$node){	      

                if(gethostbyname($node['host']) == $_SERVER['SERVER_ADDR']){
                    $localserver=$node['host'];
                }
                
                //$context=stream_context_create($opts);
                $start=$this->getmicrotime();
                $fp = @fsockopen($node['host'], 80, $errno, $errstr, $timeout);
                if ($fp) {
                        stream_set_blocking($fp, 0);
                        stream_set_timeout($fp,$timeout*2);
                        
                        fwrite($fp, "GET /index.php?eID=cluster_worker&action=systemLoad&typo3_cluster_execute=1 HTTP/1.0\r\n");
                        fwrite($fp, "Host: {$node['host']}\r\n");
                        fwrite($fp, "Connection: Close\r\n\r\n");

                        //$info = stream_get_meta_data($fp);                        
                        $sysLoad='';                        
                        while ((!feof($fp)) && (!$info['timed_out'])) {                        
                                $sysLoad .= fgets($fp, 4096);
                                $info = stream_get_meta_data($fp);
                                //sleep a little...1ms
                                usleep(100);
                                //ob_flush;
                                //flush();                                
                        }
                        //print_r($info);
                        if ($info['timed_out']) {
                                //echo "{$node['host']} timeout\n";
                                $this->sendAlert("Alert: {$node['host']} failed to reply in $timeout sec.");
                                unset($statArray[$node['host']]);
                                unset($GLOBALS["TYPO3_CONF_VARS"]["SYS"]["nodes"][$key]);
                        } else {
                                $statArray[$node['host']]=$this->getmicrotime() - $start;
                                if(!strstr($sysLoad,'OK')){
                                    unset($statArray[$node['host']]);
                                    $this->sendAlert("Alert from {$node['host']}: $sysLoad");
                                }
                        }
                }
               /* $testhandler=@fopen("http://{$node['host']}/index.php?eID=cluster_worker&action=systemLoad&typo3_cluster_execute=1","r",false,$context);
                
                if(isset($testhandler)&&$testhandler!==false){
                      
                  $statArray[$node['host']]=$this->getmicrotime() - $start;		  
                  $sysLoad = fread($testhandler,8192);
                  //$sysLoad = $testhandler;
                  //echo "{$node['host']}:$sysLoad\n";
                  if(!strstr($sysLoad,'OK')){
                    unset($statArray[$node['host']]);
                    $this->sendAlert("Alert from {$node['host']}: $sysLoad");		    
                  }
                  //fclose($testhandler);
                }else{
                     
                  $this->sendAlert("Alert: {$node['host']} failed to reply in $timeout sec.");
                  unset($statArray[$node['host']]);
                  unset($GLOBALS["TYPO3_CONF_VARS"]["SYS"]["nodes"][$key]);
                }*/
            }
            
	      //$this->_memcache->set('__statArray', $statArray, 0, 30);
	    }
	    
        //print_r($statArray);

	    if(count($statArray)==0 && $GLOBALS["TYPO3_CONF_VARS"]["SYS"]['cluster_give503']){
	      $this->sendAlert("FATAL! NO SERVERS AVAILABLE!");
	      header("HTTP/1.1 503 System currently unavailable");
	      echo "<html><head><title>System currently unavailble</title></head><body><h1>Sorry at the moment we don't have servers available to satisfy your request.</h1>
		    <p>Our manteinace department has been informed of the problem and is working to resolve it as soon as possible, please come back in a few minutes!</p></body></html>";
	      die;
	    }else if(count($statArray)==0 && !$GLOBALS["TYPO3_CONF_VARS"]["SYS"]['cluster_give503']){
            $statArray[$localserver]=0;    
	    }
	    asort($statArray);
	    reset($statArray);
	    //unset($statArray);
	    //$statArray['xslt-develop1']=1;
	    //print_r($statArray);

        //assign the first server of the array as the server cookie, so we can keep the session going on on that server
        //echo key($statArray);
        //header("Set-Cookie: typo3_cluster_server=".key($statArray));
        if(!$_COOKIE['typo3_cluster_server']){
            setcookie('typo3_cluster_server',key($statArray),0,'/');
        }
        //echo "bubu";
        //echo key($statArray);

	    if((gethostbyname(key($statArray)) != $_SERVER['SERVER_ADDR'])){	      	      	      
	      $url='http://'.key($statArray).$_SERVER['REQUEST_URI'];
	      if(trim($postData)){
            $postData.='&typo3_cluster_execute=1&typo3_cluster_name='.$_SERVER['HTTP_HOST'].'&typo3_cluster_nodes='.urlencode(serialize($GLOBALS["TYPO3_CONF_VARS"]["SYS"]["nodes"]));
	      }else{
            $postData='typo3_cluster_execute=1&typo3_cluster_name='.$_SERVER['HTTP_HOST'].'&typo3_cluster_nodes='.urlencode(serialize($GLOBALS["TYPO3_CONF_VARS"]["SYS"]["nodes"]));
	      }

	      $_GLOBALS['curl_connect'] = curl_init();
	      curl_setopt($_GLOBALS['curl_connect'], CURLOPT_TIMEOUT,300);
	      curl_setopt($_GLOBALS['curl_connect'],CURLOPT_HEADER,TRUE);
	      curl_setopt($_GLOBALS['curl_connect'], CURLOPT_URL, $url);
	      curl_setopt($_GLOBALS['curl_connect'], CURLOPT_FOLLOWLOCATION, 0);	      
	      curl_setopt($_GLOBALS['curl_connect'], CURLOPT_RETURNTRANSFER, TRUE);
//	      print_r($_COOKIE);
	      $cookiearr = array();
	      foreach($_COOKIE as $cookiename=>$cookieval){
	        $cookiearr[]="$cookiename=$cookieval";
	      }	      
	      $cookiestr=implode('; ',$cookiearr);
	      curl_setopt($_GLOBALS['curl_connect'], CURLOPT_COOKIE, "$cookiestr");
	      curl_setopt($_GLOBALS['curl_connect'], CURLOPT_HTTPHEADER, array('Expect:','User-Agent: Typo3Cluster'));
	      
	      if($postData){			
            curl_setopt($_GLOBALS['curl_connect'], CURLOPT_POST, TRUE);
            curl_setopt($_GLOBALS['curl_connect'], CURLOPT_POSTFIELDS, $postData);		
	      }
	      curl_setopt($_GLOBALS['curl_connect'], CURLOPT_ENCODING, "");                        
	      $response_content=curl_exec($_GLOBALS['curl_connect']);	    	      
	      $header=substr($response_content,0,strpos($response_content,"\r\n\r\n"));
		
	      $content=substr($response_content,strpos($response_content,"\r\n\r\n")+4);
	      //echo $header;
	      preg_match('/Set-Cookie: (.*)?/',$header,$matches);
	      if($matches[1]){
            
            header('Set-Cookie: '.$matches[1],false); 
	      }
          preg_match('/Content-type: (.*)?/',$header,$matches);
          if($matches[1]){
            	header('Content-type: '.$matches[1],false); 
	      }
	      preg_match('/Location: (.*)?/',$header,$matches);	      
	      if($matches[1]){
            header('Location: '.$matches[1]);
	      }else{
            $content .= "<!-- page dispatched by {$_SERVER['SERVER_ADDR']} and generated by: $url -->";
            echo $content;
	      }	     
	      //echo $header;
	      die;
	    }
	
	}

	private function getmicrotime(){ 
            list($usec, $sec) = explode(" ",microtime()); 
            return ((float)$usec + (float)$sec); 
	}

	/**
	 * Send an alert to the admin email
	 * @param 	string		$message: the alert message
	 * @return	void
	 */
	function sendAlert($message){
            
	    $subject = "Alert from {$_SERVER['SERVER_ADDR']} - {$_SERVER['REQUEST_URI']}";
	    $email = $GLOBALS['TYPO3_CONF_VARS']['BE']['warning_email_addr'];
	    if(trim($email)){
            //mail($email,$subject,$message);
	     // t3lib_div::plainMailEncoded($email,$subject,$message,'','quoted-printable','',false);
	    }
          
	}
    }
?>
