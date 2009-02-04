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

/**
 * Module 'Clusterizzami troia, vabbene maiale mi garba tanto' for the 'user_bubu' extension.
 *
 * @author	Cuendet IT Dept <IT@cuendet.com>
 */



	// DEFAULT initialization of a module [BEGIN]
unset($MCONF);
require ("conf.php");
require ($BACK_PATH."init.php");
require ($BACK_PATH."template.php");
$LANG->includeLLFile("EXT:typo3_cluster/mod1/locallang.xml");
require_once (PATH_t3lib."class.t3lib_scbase.php");
$BE_USER->modAccess($MCONF,1);	// This checks permissions and exits if the users has no permission for entry.
	// DEFAULT initialization of a module [END]

class typo3_cluster_module1 extends t3lib_SCbase {
	var $pageinfo;
      var $distributedServiceUrl="/index.php?eID=cluster_worker";
	/**
	 * Initializes the Module
	 * @return	void
	 */
	function init()	{
		global $BE_USER,$LANG,$BACK_PATH,$TCA_DESCR,$TCA,$CLIENT,$TYPO3_CONF_VARS;

		parent::init();

		/*
		if (t3lib_div::_GP("clear_all_cache"))	{
			$this->include_once[]=PATH_t3lib."class.t3lib_tcemain.php";
		}
		*/
	}

	/**
	 * Adds items to the ->MOD_MENU array. Used for the function menu selector.
	 *
	 * @return	void
	 */
	function menuConfig()	{
		global $LANG;
		$this->MOD_MENU = Array (
			"function" => Array (
				"2" => "Update typo3conf",
				"1" => "Check cluster health",
			)
		);
		parent::menuConfig();
	}

	/**
	 * Main function of the module. Write the content to $this->content
	 * If you chose "web" as main module, you will need to consider the $this->id parameter which will contain the uid-number of the page clicked in the page tree
	 *
	 * @return	[type]		...
	 */
	function main()	{
		global $BE_USER,$LANG,$BACK_PATH,$TCA_DESCR,$TCA,$CLIENT,$TYPO3_CONF_VARS;

		// Access check!
		// The page will show only if there is a valid page and if this page may be viewed by the user
		$this->pageinfo = t3lib_BEfunc::readPageAccess($this->id,$this->perms_clause);
		$access = is_array($this->pageinfo) ? 1 : 0;

		if (($this->id && $access) || ($BE_USER->user["admin"] && !$this->id))	{

				// Draw the header.
			$this->doc = t3lib_div::makeInstance("bigDoc");
			$this->doc->backPath = $BACK_PATH;
			$this->doc->form='<form action="index.php?id='.$this->id.'" method="POST">';

				// JavaScript
			$this->doc->JScode = '
				<script language="javascript" type="text/javascript">
					script_ended = 0;
					function jumpToUrl(URL)	{
						document.location = URL;
					}
				</script>
			';
			$this->doc->postCode='
				<script language="javascript" type="text/javascript">
					script_ended = 1;
					if (top.fsMod) top.fsMod.recentIds["web"] = 0;
				</script>
			';

			$headerSection = $this->doc->getHeader("pages",$this->pageinfo,$this->pageinfo["_thePath"])."<br />".$LANG->sL("LLL:EXT:lang/locallang_core.xml:labels.path").": ".t3lib_div::fixed_lgd_pre($this->pageinfo["_thePath"],50);

			$this->content.=$this->doc->startPage($LANG->getLL("title"));	
			exec('hostname',$ret);
			$ret=implode("",$ret);
			$this->content.=$this->doc->header($LANG->getLL("title") ." on: $ret");
			$this->content.=$this->doc->spacer(5);
			$this->content.=$this->doc->section("",$this->doc->funcMenu($headerSection,t3lib_BEfunc::getFuncMenu($this->id,"SET[function]",$this->MOD_SETTINGS["function"],$this->MOD_MENU["function"])));
			$this->content.=$this->doc->divider(5);


			// Render content:
			$this->moduleContent();


			// ShortCut
			if ($BE_USER->mayMakeShortcut())	{
				$this->content.=$this->doc->spacer(20).$this->doc->section("",$this->doc->makeShortcutIcon("id",implode(",",array_keys($this->MOD_MENU)),$this->MCONF["name"]));
			}

			$this->content.=$this->doc->spacer(10);
		} else {
				// If no access or if ID == zero

			$this->doc = t3lib_div::makeInstance("mediumDoc");
			$this->doc->backPath = $BACK_PATH;

			$this->content.=$this->doc->startPage($LANG->getLL("title"));
			$this->content.=$this->doc->header($LANG->getLL("title"));
			$this->content.=$this->doc->spacer(5);
			$this->content.=$this->doc->spacer(10);
		}
	}

	/**
	 * Prints out the module HTML
	 *
	 * @return	void
	 */
	function printContent()	{

		$this->content.=$this->doc->endPage();
		echo $this->content;
	}

	/**
	 * Generates the module content
	 *
	 * @return	void
	 */
	function moduleContent()	{
		if(!is_array($GLOBALS["TYPO3_CONF_VARS"]["SYS"]["nodes"])){
		  $content="<h2 style=\"color:red;\">NO SERVERS CONFIGURED, PLASE SETUP YOUR NODES IN localconf.php</h2>";
		  $this->content.=$this->doc->section("No servers available",$content,0,1);
		  return;
		}
		switch((string)$this->MOD_SETTINGS["function"])	{
			case 2:
                  /*
				$content="<div align=center><strong>Hello World!</strong></div><br />
					The 'Kickstarter' has made this module automatically, it contains a default framework for a backend module but apart from it does nothing useful until you open the script '".substr(t3lib_extMgm::extPath("typo3_cluster"),strlen(PATH_site))."mod1/index.php' and edit it!
					<HR>
					<br />This is the GET/POST vars sent to the script:<br />".
					"GET:".t3lib_div::view_array($_GET)."<br />".
					"POST:".t3lib_div::view_array($_POST)."<br />".
					"";
                              */
                              
			      $mode=$_POST["mode"];
			      if (stristr("update",$mode)){
				    $file=$_POST["updfile"];
				    $servers=$_POST["server_selected"];
				    $this->content.=$this->updateServers($file,$servers);
			      }else{
				    $content=$this->getUpdateform($mode);
				    $this->content.=$this->doc->section("Update typo3conf to all servers:",$content,0,1);
			      }
                        
			      break;

			case 1:
			      foreach($GLOBALS["TYPO3_CONF_VARS"]["SYS"]["nodes"] as $node){				
				  $start=$this->getmicrotime();
				  $testhandler=@fopen("http://{$node['host']}/index.php?eID=cluster_worker&action=systemStatus&typo3_cluster_execute=1","r");
				  if(!$testhandler){
				    $nodestat[$node['host']]['alive']=false;
				  }else{
				    $nodestat[$node['host']]['alive']=true;
				    $nodestat[$node['host']]['ping']=$this->getmicrotime() - $start;				    
				    $nodestat[$node['host']]['sys']=fread($testhandler,8192);
				    fclose($testhandler);
				  }	  				
			      }
			      
			      
			      $content="<table align=\"center\" border=\"1\" cellpadding=\"8\"><tr><th>Host:</th><th>Status:</th><th>Ping:</th><th>System Status:</th></tr>";
			      foreach($nodestat as $host=>$nodedata){
				if($nodedata['alive']){
				  $status="<span style=\"color:#0F0;font-weight:bold;\">ALIVE</span>";
				  $ping=round(($nodedata['ping']*1000))." ms";
				  $sys="<span style=\"font-family:Courier New,fixed;font-size:11px;\">{$nodedata['sys']}</span>";
				}else{
				  $status="<span style=\"color:#F00;font-weight:bold;\">DEAD</span>";
				  $ping="N/A";
				  $sys="N/A";
				}
				$content.="<tr><td>$host</td><td>$status</td><td>$ping</td><td>$sys</td></tr>";
			      }			      
			      $content.="</table>";
			      $this->content.=$this->doc->section("Check cluster health:",$content,0,1);
			break;
			case 3:
				$content="<div align=center><strong>Menu item #3...</strong></div>";
				$this->content.=$this->doc->section("Message #3:",$content,0,1);
			break;
		}
	}
      /**   This function send update request to others servers
            @param      string         $mode: id of the file/directory to update
                                                      localconf - localconf.php
                                                      typo3conf - directory typo3conf
            @param      array           $servers: optionale server list to update
      
      */
      private function updateServers($mode,$servers=Array()){
            
            switch ($mode){
                                    case "typo3conf":{
                                          $dest=PATH_site."typo3temp/__".time()."typo3conf.tar.gz";
                                          $src="typo3conf/";
                                          break;
                                    }
                                    
                                    case "localconf":{
                                          $dest=PATH_site."typo3temp/__".time()."localconf.tar.gz";                                          
                                          $src="typo3conf/localconf.php";
                                          break;
                                    }
                                    
                                    case "fileadmin":{
                                          $dest=PATH_site."typo3temp/__".time()."fileadmin.tar.gz";                                          
                                          $src="fileadmin/";
                                          break;
                                    }
                                    
                                    
                        
            }
            
            $cmd="tar -C".PATH_site." -zcvf $dest $src";	    
            exec($cmd,$out,$retVal);   
            
            if (isset($retVal)&&$retVal==0){
                  $params["action"]="uploadBackup";
                  $params["file"]="@$dest";
                  $params["destination"]=PATH_site."typo3temp/localconf.tar.gz";
		  $params['typo3_cluster_execute']=1;
                  $results=$this->sendRequestToNodes($params,$this->distributedServiceUrl,$servers);
                  if ($results){
                        foreach($results as $srv=>$res){
                              $this->content.="Error in $srv: ".$res;
                        }
                  }else{
                        $this->content.="All files updated successfully on all servers";
                        $this->content.=$this->getUpdateform();
                  }
                  unlink($dest);
            }else{
                  echo "Error in zip process";
            }
            
      }
      
      /**   This function provide the html code to show buttons to send updated files to other servers
      
      */
      private function getUpdateform(){
	    
            $servers=count($GLOBALS["TYPO3_CONF_VARS"]["SYS"]["nodes"]);
            $nodesselect="<select multiple size='$servers' name='server_selected[]'>";
            
            foreach($GLOBALS["TYPO3_CONF_VARS"]["SYS"]["nodes"] as $node){
                   if(gethostbyname($node['host']) != $_SERVER["SERVER_ADDR"]){
                        $nodesselect.="<option value='{$node["host"]}' selected>{$node["host"]}</option>";
                  }
            }
            
            $nodesselect.="</select>";
            
            
            
            $content="
                        
                              <div style='width:100%;'>
                              <table width='100%' cellspacing='10'>
                                    <tr>
                                          <td align='right'>
                                                <input type='radio' name='updfile' value='localconf' checked/>
                                          </td>
                                          <td align='left'>
                                                Update only localconf.php
                                          </td>
                                    </tr>
                                    <tr>
                                          <td align='right'>
                                                <input type='radio' name='updfile' value='typo3conf'/>
                                          </td>
                                          <td align='left'>
                                                Update typo3conf dir
                                          </td>
                                    </tr>
                                    <tr>
                                          <td align='right'>
                                                <input type='radio' name='updfile' value='fileadmin'/>
                                          </td>
                                          <td align='left'>
                                                Update fileadmin dir
                                          </td>
                                    </tr>
                                    <tr>
                                          <td align='right' width='50%'>
                                                Choose server/s:
                                          </td>
                                          <td align='left' width='50%'>
                                                $nodesselect
                                          </td>
                                    </tr>
                                    <tr>
                                          <td colspan='2' align='center'>
                                                <input type='submit' name='mode' value='Update'/>
                                          </td>
                                    </tr>
                              </table>
                              </div>
                        
            ";
            
            
            return $content;
      }
      
      /**
            Send request to nodes for modified file(new,updated,move,deleted,...)
            @param      array       $params:parameters of the request     
            */
      function sendRequestToNodes($params,$url,$nodes=Array()){
            $estimateSpeed=200;
            $timeout=ceil(($GLOBALS["TYPO3_CONF_VARS"]["BE"]["maxFileSize"])/$estimateSpeed);
            
            $handlers=Array();
            $connects=curl_multi_init();
            if (!$nodes){
                  if ($GLOBALS["TYPO3_CONF_VARS"]['SYS']['nodes']){
                        $local_ip=$_SERVER['SERVER_ADDR'];
                        foreach ($GLOBALS["TYPO3_CONF_VARS"]['SYS']['nodes'] as $data){
                              if(gethostbyname($data['host']) != $local_ip){
                                    $nodes[]=$data["host"];
                              }
                        }
                  }
            }
            
            foreach($nodes as $node_ip){
                  $connect=curl_init();
                  $url="http://".$node_ip.$this->distributedServiceUrl;
                  //echo $url;
                  curl_setopt($connect, CURLOPT_TIMEOUT,$timeout);
                  curl_setopt($connect, CURLOPT_URL, $url);
                  curl_setopt($connect, CURLOPT_FOLLOWLOCATION, 1);
                  curl_setopt($connect, CURLOPT_MAXREDIRS,10);
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
                        echo (curl_error($res["handle"]));                        
                  }
            }
            $results=Array();
            
            foreach($handlers as $index=>$curl){
                  $content=curl_multi_getcontent($curl);
                  
                  
                  if (!stristr($content,"OK")){
                        $results[$nodes[(int)$index]]=$content;
                        //$this->sendAlert($this->nodes[$index],$content);
                        //$this->writelog(0,1,0,"Error in {$this->nodes[$index]}: ".$content,Array());
                    //    echo $content;
                  }
            }
            
            return $results;
      }
    
      private function getmicrotime(){ 
            list($usec, $sec) = explode(" ",microtime()); 
            return ((float)$usec + (float)$sec); 
      }
}



if (defined('TYPO3_MODE') && $TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/typo3_cluster/mod1/index.php'])	{
	include_once($TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/typo3_cluster/mod1/index.php']);
}




// Make instance:
$SOBE = t3lib_div::makeInstance('typo3_cluster_module1');
$SOBE->init();

// Include files?
foreach($SOBE->include_once as $INC_FILE)	include_once($INC_FILE);

$SOBE->main();
$SOBE->printContent();

?>