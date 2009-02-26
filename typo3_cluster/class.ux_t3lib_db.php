<?php
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

class ux_t3lib_DB extends t3lib_DB{



	var $_memcache = ''; //the memcache connection object

	var $_lastQueryHash = ''; //an hash of the last query, to be used as cache key
	
	var $_nodes=Array();
      
	var $_distributedServiceUrl="/index.php?eID=cluster_worker";
      
	var $_table_exclusion_list=Array();
	
	var $_estimateSpeed="6250"; //bytes, that are 50Kbit/sec

	var $_queryPack = array();
	
	/**
         * Constructor: create the memcached connection
         */
	function __construct(){

        $this->_table_exclusion_list[]='be_sessions';
        /*$this->_table_exclusion_list[]='cache_extensions';
        $this->_table_exclusion_list[]='cache_hash';
        $this->_table_exclusion_list[]='cache_imagesizes';
        $this->_table_exclusion_list[]='cache_md5params';
        $this->_table_exclusion_list[]='cache_pages';
        $this->_table_exclusion_list[]='cache_pagesection';
        $this->_table_exclusion_list[]='cache_pages_id';
        $this->_table_exclusion_list[]='cache_typo3temp_log';*/
        $this->_table_exclusion_list[]='fe_sessions';
        $this->_table_exclusion_list[]='fe_session_data';
        $this->_table_exclusion_list[]='tx_realurl_chashcache';
        $this->_table_exclusion_list[]='tx_realurl_pathcache';
        $this->_table_exclusion_list[]='tx_realurl_urldecodecache';
        $this->_table_exclusion_list[]='tx_realurl_urlencodecache';
        $this->_table_exclusion_list[]='tx_realurl_errorlog';
        $this->_table_exclusion_list[]='tx_realurl_uniqalias';
        $this->_table_exclusion_list[]='tx_realurl_redirects';
        
	    /*if(class_exists(Memcache)){
	      $this->_memcache = new Memcache;
	      $res=@$this->_memcache->connect('localhost', 11211);
	      if(!$res){
		$message = "{$_SERVER['SERVER_ADDR']} : memcached not available";
		$this->sendAlert($message);
	      }
	    }*/
	    
	    // Call post processing function for constructor:
	    if (is_array($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['typo3_cluster/class.ux_t3lib_db.php']['ux_t3lib_db-PostProc']))	{
		$_params = array();
		foreach($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['typo3_cluster/class.ux_t3lib_db.php']['ux_t3lib_db-PostProc'] as $_funcRef)	{
			t3lib_div::callUserFunction($_funcRef,$_params,$this);
		}
	    }	    
	    if(is_array($GLOBALS['TYPO3_CONF_VARS']['SYS']['nodes'])){
	      foreach($GLOBALS['TYPO3_CONF_VARS']['SYS']['nodes'] as $data){
		if(gethostbyname($data['host']) != $_SERVER['SERVER_ADDR']){
		  $this->_nodes[]=$data['host'];
		}
	      }
            
	    }
	}


	/**
	 * Destructor: sends all the queries in a unique pack to the nodes of the cluster
	 */
	function __destruct(){
      
	  //echo serialize($this);
	  if($this->_queryPack){
	    $params['action']='query';
	    //if(function_exists('gzcompress')){
	    //  $params['query']=gzcompress(implode("|*|",$this->_queryPack),5);
	    //  $params['compressed']=1;
	    //}else{
	      $params['query']=implode("|*|",$this->_queryPack);
	    //}
	    $params['typo3_cluster_execute']=1;
	    //print_r($this->_nodes);
	    $this->sendRequestToNodes($params);
	  }	  
	}



	/************************************
	 *
	 * Query execution
	 *
	 * These functions are the RECOMMENDED DBAL functions for use in your applications
	 * Using these functions will allow the DBAL to use alternative ways of accessing data (contrary to if a query is returned!)
	 * They compile a query AND execute it immediately and then return the result
	 * This principle heightens our ability to create various forms of DBAL of the functions.
	 * Generally: We want to return a result pointer/object, never queries.
	 * Also, having the table name together with the actual query execution allows us to direct the request to other databases.
	 *
	 **************************************/


	/**
	 * Creates and executes a SELECT SQL-statement
	 * Using this function specifically allow us to handle the LIMIT feature independently of DB.
	 * Usage count/core: 340
	 *
	 * @param	string		List of fields to select from the table. This is what comes right after "SELECT ...". Required value.
	 * @param	string		Table(s) from which to select. This is what comes right after "FROM ...". Required value.
	 * @param	string		Optional additional WHERE clauses put in the end of the query. NOTICE: You must escape values in this argument with $this->fullQuoteStr() yourself! DO NOT PUT IN GROUP BY, ORDER BY or LIMIT!
	 * @param	string		Optional GROUP BY field(s), if none, supply blank string.
	 * @param	string		Optional ORDER BY field(s), if none, supply blank string.
	 * @param	string		Optional LIMIT value ([begin,]max), if none, supply blank string.
	 * @return	pointer		MySQL result pointer / DBAL object
	 */
	function exec_SELECTquery($select_fields,$from_table,$where_clause,$groupBy='',$orderBy='',$limit='')	{
		$query = $this->SELECTquery($select_fields,$from_table,$where_clause,$groupBy,$orderBy,$limit);
		return $this->sql_query($query);
	}

	/**
	 * Creates and executes an INSERT SQL-statement for $table from the array with field/value pairs $fields_values.
	 * Using this function specifically allows us to handle BLOB and CLOB fields depending on DB
	 * Usage count/core: 47
	 *
	 * @param	string		Table name
	 * @param	array		Field values as key=>value pairs. Values will be escaped internally. Typically you would fill an array like "$insertFields" with 'fieldname'=>'value' and pass it to this function as argument.
	 * @param	string/array		See fullQuoteArray()
	 * @return	pointer		MySQL result pointer / DBAL object
	 */
	function exec_INSERTquery($table,$fields_values,$no_quote_fields=FALSE)	{
		//$res = mysql_query($this->INSERTquery($table,$fields_values,$no_quote_fields), $this->link);
		//if ($this->debugOutput)	$this->debug('exec_INSERTquery');        
		$res = $this->sql_query($this->INSERTquery($table,$fields_values,$no_quote_fields));        
		return $res;
	}

	/**
	 * Creates and executes an UPDATE SQL-statement for $table where $where-clause (typ. 'uid=...') from the array with field/value pairs $fields_values.
	 * Using this function specifically allow us to handle BLOB and CLOB fields depending on DB
	 * Usage count/core: 50
	 *
	 * @param	string		Database tablename
	 * @param	string		WHERE clause, eg. "uid=1". NOTICE: You must escape values in this argument with $this->fullQuoteStr() yourself!
	 * @param	array		Field values as key=>value pairs. Values will be escaped internally. Typically you would fill an array like "$updateFields" with 'fieldname'=>'value' and pass it to this function as argument.
	 * @param	string/array		See fullQuoteArray()
	 * @return	pointer		MySQL result pointer / DBAL object
	 */
	function exec_UPDATEquery($table,$where,$fields_values,$no_quote_fields=FALSE)	{
		//$res = mysql_query($this->UPDATEquery($table,$where,$fields_values,$no_quote_fields), $this->link);
		//if ($this->debugOutput)	$this->debug('exec_UPDATEquery');
		$res = $this->sql_query($this->UPDATEquery($table,$where,$fields_values,$no_quote_fields));
		return $res;
	}

	/**
	 * Creates and executes a DELETE SQL-statement for $table where $where-clause
	 * Usage count/core: 40
	 *
	 * @param	string		Database tablename
	 * @param	string		WHERE clause, eg. "uid=1". NOTICE: You must escape values in this argument with $this->fullQuoteStr() yourself!
	 * @return	pointer		MySQL result pointer / DBAL object
	 */
	function exec_DELETEquery($table,$where)	{
		//$res = mysql_query($this->DELETEquery($table,$where), $this->link);
		//if ($this->debugOutput)	$this->debug('exec_DELETEquery');
		$res = $this->sql_query($this->DELETEquery($table,$where));
		return $res;
	}

	/**************************************
	 *
	 * MySQL wrapper functions
	 * (For use in your applications)
	 *
	 **************************************/

	

	/**
	 * Executes query
	 * mysql_query() wrapper function
	 * Usage count/core: 1
	 *
	 * @param	string		Query to execute
	 * @return	pointer		Result pointer / DBAL object
	 */
	function sql_query($query)	{
   
       //try to read the transaction log, if it exists it means that somwhere in the past we were
       //unable to performa query on a node, so we try it again!
       $data=@file_get_contents(PATH_typo3conf.'t3cluster_transaction.log');
       //if $data is getting big, very big, that means that for some reason we were unable to perform the queries many consecutive times, 
       //i's a wise thing to do to send an email to the cluster admin sayng that...
       if(strlen($data)>1048576){ //that's 1MB of log...
           $this->sendAlert('Transaction log is bigger that 1MB! may be you have connection problems?');
       }
       if($data){
            $tmp1=explode('|*|',$data);
            foreach($tmp1 as $tmpquery){
                if(trim($tmpquery)){
                    $this->_queryPack[]=$tmpquery;
                }
            }
            unlink(PATH_typo3conf.'t3cluster_transaction.log');
        }
        //if there are multiple conenctions active to our database pool then make eavery insert and every update on every database
                        
		if(count($this->_nodes)>0 && (substr($query,0,6)!='SELECT')){
                  $regexp=implode("|",$this->_table_exclusion_list);
                  $regexp="/$regexp/";
                  preg_match($regexp,$query,$matches);
                
            
                  //if the table is not in the exclude tables array...
                  if (!$matches[0] || (substr($query,0,6)=='DELETE')) {
                    $this->_queryPack[]=$query;   
                  }		        
		}
				
		$retval=$this->sql_local_query($query);
		if($retval){
		  return $retval;
		}
	}

	/**
	 * Makes a query to the local database
	 * @param 	string	$query, the query to be executed on the local database
	 * @return	mixed	an array containing the query result or 0 on failure
	 */
	function sql_local_query($query){
	
	 /**
	  * TODO: FIND A MORE ELEGANT SOLUTION FOR THIS, IT MAY HAPPEN THAT WE MAKE DOUBLE ENTRIES IN THE CONTENT CACHE, SO 
	  *	   INSTEAD OF MAKING INSERTS WE ALWAYS DO "REPLACE" BUT IT ONLY WORKS WITH MYSQL!!!!
	  */
      $query=str_replace("INSERT","REPLACE",$query);
	  //echo $_SERVER['SERVER_ADDR'].'-'.$query.'-';
	  //$res = mysql_query($query, $this->link);
      $res = mysql_query($query);
	  //echo "***$res***";
	  if(!$res){
	    //echo $this->sql_error();
	    $this->sendAlert("Error executing *** $query ***\n".$this->sql_error());
	    return 0;
	  }
	 
	  //print_r($tmpArr);
	  return $res;		  
	}

	
	
	/**
	 * Send an alert to the admin email
	 * @param 	string		$message: the alert message
	 * @return	void
	 */
	function sendAlert($message){
	    $subject = "Alert from {$_SERVER['SERVER_ADDR']} - database";
	    $email = $GLOBALS['TYPO3_CONF_VARS']['BE']['warning_email_addr'];
	    if(trim($email)){
	      t3lib_div::plainMailEncoded($email,$subject,$message,'','quoted-printable','',false);
	    }
	}
	
	 /**
          *  Send request to nodes for modified file(new,updated,move,deleted,...)
          *  @param      array       $params:parameters of the request     
          */
	function sendRequestToNodes($params){
	    //print_r($params);
	    //if(is_array($params['query'])){
	    //  $params['query']=serialize($params['query']);
	    //}
          
          $postdata="";
          foreach($params as $key=>$value){
            $postdata.="$key=$value&";
          }
          //$postdata=urlencode($postdata);
          $postdata=str_replace("\n"," ",$postdata);
          /*$f=fopen("/tmp/tmp.txt","w");
          fwrite($f,$postdata);
          fclose($f);*/
            //foreach($this->_nodes as $node_ip){
                  /*$helperPath = PATH_site."typo3conf/ext/typo3_cluster/helper.php";
                  $url = urlencode("http://".$node_ip.$this->_distributedServiceUrl."&typo3_cluster_execute=1");
                  $postdata=urlencode($postdata);
                  exec("php $helperPath --url=$url --post-data=$postdata ");
                  */
            
            
                //$timeout=ceil(strlen($postdata)/$this->_estimateSpeed);
                $timeout=round(strlen($postdata)/$this->_estimateSpeed);
                //$timeout=5;
                

                $handlers=Array();
                $connects=curl_multi_init();	
                foreach($this->_nodes as $node_ip){
                      $connect=curl_init();
                      $url="http://".$node_ip.$this->_distributedServiceUrl."&typo3_cluster_execute=1";
                      curl_setopt($connect, CURLOPT_TIMEOUT,$timeout);
                      curl_setopt($connect, CURLOPT_URL, $url);                  
                      curl_setopt($connect, CURLOPT_RETURNTRANSFER, true);
                      curl_setopt($connect, CURLOPT_HTTPHEADER, array('Expect:'));
                      curl_setopt($connect, CURLOPT_POST, true);
                      curl_setopt($connect, CURLOPT_POSTFIELDS, $params);
                      curl_setopt($connect, CURLOPT_ENCODING, "");
                      curl_multi_add_handle($connects,$connect);
                      $handlers[]=$connect;
                      //echo curl_exec($connect);
                      //curl_close($connect);
                }
                $running=null;
                //$start=user_cuendetxsl_pi1::getmicrotime();
                do {
                      $mrc = curl_multi_exec($connects, $running);
                } while ($mrc == CURLM_CALL_MULTI_PERFORM);

                while ($running && $mrc == CURLM_OK) {
                    if (curl_multi_select($connects) != -1) {
                        do {
                            $mrc = curl_multi_exec($connects, $running);
                        } while ($mrc == CURLM_CALL_MULTI_PERFORM);
                    }
                }
                while ($res=curl_multi_info_read($connects)){                  
                      if ($res["result"]!=0){
                            $string='';
                            $data=curl_getinfo($res['handle']);
                            foreach($data as $key=>$itm){
                                $string.="$key = $itm\n";
                            }
                            if($GLOBALS['TYPO3_CONF_VARS']['SYS']['cluster_debug']){
                                $this->sendAlert($string."\n".curl_error($res["handle"]));                        
                            }
                      }
                }
                
                foreach($handlers as $index=>$curl){
                      $content=curl_multi_getcontent($curl);
                      //echo $content;
                      if (!stristr($content,"OK")){
                            //$this->sendAlert("Error node {$this->_nodes[$index]} returned: $content");
                            //echo "Error in {$this->nodes[$index]}: ".$content;
                            
                            //if the transaction has failed, put the query in a log file and try to execute it on the next load
                            $logres=file_put_contents(PATH_typo3conf.'t3cluster_transaction.log',$params['query'].'|*|',FILE_APPEND|LOCK_EX);
                            if(!$logres){
                                $this->sendAlert("Unable to write to transaction log file!");
                            }
                      }
                }
            //}
            
	}
	
}


if (defined('TYPO3_MODE') && $TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/typo3_cluster/class.ux_t3lib_db.php'])    {
    include_once($TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/typo3_cluster/class.ux_t3lib_db.php']);
}
?>
