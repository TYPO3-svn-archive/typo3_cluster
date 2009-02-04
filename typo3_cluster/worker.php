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
 * This is the worker for the cluster service, is called by the low level classes and replicates the data on each node
 * each function of the worker may return OK or FATAL with its own error message
 *
 * IMPLEMENTED FUNCTIONS:
 * 
 * the name of the function is passed on $_POST['action']
 *
 * query:
 * execute the received query in $_POST['query'] on the local db, multiple query can be passed separated by a pipe (|) 
 *
 * uploadFile:
 * uploads a file on the local machine, puts the file in $_POST['destination']
 *
 * deleteFile:
 * delete a file on the local machine, the file is pecified in $_POST['destination']
 *
 * deleteDir:
 * delete a directory on the local machine, the directory is specified in $_POST['directory'], if $_POST['r'] is set
 * the directory is deleted recursively, even if is not empty
 *
 * newDir:
 * creates the directory specified in $_POST['directory']
 *
 * newFile:
 * creates the file specified in $_POST['destination']
 *
 * copyDir:
 * copies the directory specified in $_POST['sourceDir'] in $_POST['destinationDir']
 *
 * copyFile:
 * copies the file specified in $_POST['sourceFile'] is $_POST['destinationFile']
 *
 * moveFile:
 * moves the file specified in $_POST['sourceFile'] is $_POST['destinationFile']
 * 
 * moveDir:
 * moves the directory specified in $_POST['sourceDir'] in $_POST['destinationDir']
 *
 * editFile:
 * modify the content of the file specified in $_POST['destination'] using the value of $_POST['content']
 *
 * uploadBackup:
 * receive a tar.gz file and uncompress it replacing the old files (used to sync typo3conf dirs across servers)
 *
 * systemStatus:
 * returns inforamtions about the load of the system, for statistical purpouse
 *
 * @author Saverio Vigni <s.vigni@hor-net.com>
 * @author Federico Stendardi <stenda85@alice.it> 
 */

// Exit, if script is called directly (must be included via eID in index_ts.php)
if (!defined ('PATH_typo3conf')) die ('Could not access this script directly!');

tslib_eidtools::connectDB();

$found=false;

if(is_array($GLOBALS['TYPO3_CONF_VARS']['SYS']['nodes'])){
  foreach($GLOBALS['TYPO3_CONF_VARS']['SYS']['nodes'] as $node){    
  //echo gethostbyname($node['host']);
    if(gethostbyname($node['host']) == $_SERVER['REMOTE_ADDR']){
      $found=true;
    }
  }
}
if($found==false){ 
  echo "FATAL - This host is not allowed to use this service";
  die;
}

if(!$_POST['action']){
  $_POST['action']=$_GET['action'];
}
switch($_POST['action']){
  case 'query':

    if($_POST['compressed']){
      $_POST['query']=gzuncompress($_POST['query']);
    }
    $_POST['query']=explode('|*|',$_POST['query']);
    
    foreach($_POST['query'] as $query){
      if(trim($query)){
	//if(get_magic_quotes_gpc()){
	  $query=stripslashes($query);
	//}
	$res=$GLOBALS['TYPO3_DB']->sql_local_query($query);
	if($res==0){ break;}
      }
    }
    
    if($res==0){
      $retMessage="FATAL - Execution of $query failed! ". $GLOBALS['TYPO3_DB']->sql_error();
    }else{
      $retMessage="OK - query $query executed successfully";
    }
    break;
  
  case 'uploadFile':
            $destinationPath=$_POST["destination"];
	    $retMessage=uploadFile($destinationPath);
            break;
            
      /* FILE AND DIRECTORY REMOVAL */
      case 'deleteFile':
            $destinationPath=$_POST["destination"];
            if (file_exists($destinationPath)){
                  if (unlink($destinationPath)){
                        $retMessage="OK - file $destinationPath has been correctly removed";
                  }else{
                        $retMessage="FATAL - an error occured during removal of $destinationPath";
                  }
                  
            }else{
                  $retMessage="FATAL - file $destinationPath does not exists";
            }
            break;
    
      case 'deleteDir':
            $dir=$_POST["directory"];
            $r=$_POST["r"];
            if (is_dir($dir)){
                  if ($r==0){
                        if (rmdir($dir)){
                              $retMessage="OK - directory $dir has been correctly removed";
                        }else{
                              $retMessage="FATAL - an error occured during removal of directory $dir";
                        }
                  }else if($r==1){
                       if (t3lib_div::rmdir($dir,true))	{
                              $retMessage="OK - directory $dir has been correctly removed";
                       }else{
                              $retMessage="FATAL - an error occured during removal of directory $dir";
                       }
                  }
                  
                  
            }else{
                  $retMessage="FATAL - directory $dir does not exists";
            }
      break;
      
      /* NEW FILE AND DIRECTORY CREATION */
      case 'newDir':
            $dir=$_POST["directory"];
            
                  
                        if (t3lib_div::mkdir($dir)){
                              $retMessage="OK - directory $dir correctly created";
                        }else{
                              $retMessage="FATAL - an error occured during the creation of directory $dir";
                        }
      break;
      
      case 'newFile':
            $destinationPath=$_POST["destination"];
            if (t3lib_div::writeFile($destinationPath,''))
            {  
                       // everything went fine...
                       $retMessage="OK - File $destinationPath correctly created";  
             }  
             else  
             {  
                 // an error has occured
                 $retMessage="FATAL - unable to move file in the specified destination";  
             }  
            
      break;      
      
      /* FILE AND DIRECTORY COPY */
      case 'copyDir':
            $source=$_POST["sourceDir"];
            $destination=$_POST["destinationDir"];
            $cmd = 'cp -R "'.$source.'" "'.$destination.'"';
            exec($cmd);
            if (@is_dir($destination)){
                              $retMessage="OK - directory $source correctly copied to $destination";
            }else{
                              $retMessage="FATAL - error copying directory $source to $destination";
            }
      break;
      
      case 'copyFile':
            $source=$_POST["sourceFile"];
            $destination=$_POST["destinationFile"];
           copy ($source,$destination);
            if (@file_exists($destination)){
                              $retMessage="OK - file $source correctly copied to $destination";
            }else{
                              $retMessage="FATAL - error copying file $source to $destination";
            }
      break;
      
      /* RENAME AND MOVE OF FILES AND DIRECTORY*/
      
      case 'moveFile':
            $source=$_POST["sourceFile"];
            $destination=$_POST["destinationFile"];
            rename ($source,$destination);
            if (@file_exists($destination)){
                              $retMessage="OK - file $source correctly moved to $destination";
            }else{
                              $retMessage="FATAL - error moving file $source to $destination";
            }
      break;

      case 'moveDir':
            $source=$_POST["sourceDir"];
            $destination=$_POST["destinationDir"];
            rename ($source,$destination);
            
            if (@is_dir($destination)){
                              $retMessage="OK - directory $source correctly moved to $destination";
            }else{
                              $retMessage="FATAL - error moving directory $source to $destination";
            }
      break;

      /* MODIFY FILE CONTENT */
      
      case 'editFile':
            $destinationPath=$_POST["destination"];
            $content=$_POST["content"];
            if (t3lib_div::writeFile($destinationPath,$content)){
                  $retMessage="OK - File $destinationPath correctly modified";  
            }else{
                  $retMessage="FATAL - Error modifying file $destinationPath";  
            }
      break;
       
      /* UPLOAD and TAR-UNZIP a file */
      case 'uploadBackup':
	$destinationPath=PATH_site.'typo3temp/bckTmp'.time().'.tar.gz';	
	$retVal=uploadFile($destinationPath);
	if(!strstr($retVal,'OK')){
	  $retMessage = $retVal;
	  break;
	}else{
	  $tmpDir=ini_get('upload_tmp_dir');
	  $cmd = 'tar -zxvf '.$destinationPath;	  	  
	  exec($cmd,$out,$ret);	  
	  if($ret==0){
	    $retMessage = 'OK - Backup uploaded and installed successfully';
	  }else{
	    $retMessage = 'FATAL - Backup uncompress failed!';
	  }
	  unlink($destinationPath);
	}
	
      break;

      case 'systemStatus':
	exec('uptime',$uptime);
	exec('free -m',$free);
	exec('df -h',$df);
	exec('netstat -t --numeric | grep :80 | grep ESTABLISHED | wc -l',$port80load);
	
	$loadThreshold=2;
	if($GLOBALS["TYPO3_CONF_VARS"]["SYS"]['load_threshold']){
	  $loadThreshold=$GLOBALS["TYPO3_CONF_VARS"]["SYS"]['load_threshold'];
	}
	$memThreshold=0.8;
	if($GLOBALS["TYPO3_CONF_VARS"]["SYS"]['memory_threshold']){
	  $memThreshold=$GLOBALS["TYPO3_CONF_VARS"]["SYS"]['memory_threshold']/100;
	}
	$diskThreshold=90;
	if($GLOBALS["TYPO3_CONF_VARS"]["SYS"]['disk_threshold']){
	  $diskThreshold=$GLOBALS["TYPO3_CONF_VARS"]["SYS"]['disk_threshold'];
	}
	$netThreshold=40;
	if($GLOBALS["TYPO3_CONF_VARS"]["SYS"]['network_threshold']){
	  $netThreshold=$GLOBALS["TYPO3_CONF_VARS"]["SYS"]['network_threshold'];
	}
	$ret='';	
	$retuptime="<b>Uptime & Load:</b><br>";
	$retuptime.=implode('<br>',$uptime).'<hr>';
	//check for system overload!
	preg_match('/load average: ([0-9.]*)?/',$retuptime,$matches);
	$retuptime=str_replace(" ","&nbsp;",$retuptime);
	if((float)trim($matches[1]) >= $loadThreshold){
	  $retuptime="<span style=\"color:#F00;font-weight:bold;\">$retuptime</span>";
	}
	$ret.=$retuptime;

	$retmemory="<b>Memory (MB):</b><br>";
	$retmemory.=implode('<br>',$free).'<hr>';
	preg_match('/Mem:\s*([0-9]*)?.*-\/\+ buffers\/cache:\s*([0-9]*)?/',$retmemory,$matches);
	$retmemory=str_replace(" ","&nbsp;",$retmemory);
	if($matches[2]/$matches[1]>=$memThreshold){
	  $retmemory="<span style=\"color:#F00;font-weight:bold;\">$retmemory</span>";
	}	
	$ret.=$retmemory;

	$retdisk="<b>Disk:</b><br>";
	$retdisk.=implode('<br>',$df).'<hr>';
	preg_match_all('/([0-9]*)%/',$retdisk,$matches);
	$retdisk=str_replace(" ","&nbsp;",$retdisk);
	foreach($matches[1] as $diskload){
	  if($diskload >= $diskThreshold){
	    $retdisk="<span style=\"color:#F00;font-weight:bold;\">$retdisk</span>";
	  }
	}
	$ret.=$retdisk;

	$ret80load="<b>Connections on port 80:&nbsp;</b>";
	$ret80load.=implode('<br>',$port80load).'<hr>';
	if((int)$port80load > $netThreshold){
	    $ret80load="<span style=\"color:#F00;font-weight:bold;\">$ret80load</span>";
	}
	$ret.=$ret80load;

	$retMessage = $ret;
      break;
  
  case 'systemLoad':
      $retMessage = "OK";
      exec('df -h',$df);
      //exec('free -m',$free);
      exec('uptime',$uptime);

      $diskThreshold=90;
      if($GLOBALS["TYPO3_CONF_VARS"]["SYS"]['disk_threshold']){
        $diskThreshold=$GLOBALS["TYPO3_CONF_VARS"]["SYS"]['disk_threshold'];
      }
      $loadThreshold=2;
      if($GLOBALS["TYPO3_CONF_VARS"]["SYS"]['load_threshold']){
        $loadThreshold=$GLOBALS["TYPO3_CONF_VARS"]["SYS"]['load_threshold'];
      }
      /*$memThreshold=0.8;
      if($GLOBALS["TYPO3_CONF_VARS"]["SYS"]['memory_threshold']){
	$memThreshold=$GLOBALS["TYPO3_CONF_VARS"]["SYS"]['memory_threshold']/100;
      }*/

      $retuptime.=implode('<br>',$uptime).'<hr>';
      //check for system overload!
      preg_match('/load average: ([0-9.]*)?/',$retuptime,$matches);
      //$retuptime=str_replace(" ","&nbsp;",$retuptime);
      if((float)trim($matches[1]) >= $loadThreshold){
	  $retMessage = "FATAL - system load higher than $loadThreshold";
      }

      $retdisk=implode('<br>',$df).'<hr>';
      preg_match_all('/([0-9]*)%/',$retdisk,$matches);
      foreach($matches[1] as $diskload){
	if($diskload >= $diskThreshold){
	  $retMessage = "FATAL - disk occupancy over $diskThreshold";
	}
      }
      /*$retmemory.=implode('<br>',$free).'<hr>';
      preg_match('/Mem:\s*([0-9]*)?.*-\/\+ buffers\/cache:\s*([0-9]*)?/',$retmemory,$matches);      
      if($matches[2]/$matches[1]>=$memThreshold){
	$retMessage = "FATAL - memory usage over 80%";
      }*/
      
      break;

  default:
    $retMessage = "FATAL - You must specify an action";
    break;
}

echo $retMessage;


function uploadFile($destinationPath){

  if (is_uploaded_file($_FILES['file']['tmp_name'])){
    if(trim($destinationPath)){
      if (move_uploaded_file($_FILES['file']['tmp_name'], $destinationPath))  
      {  
	// se il salvataggio è andato a buon fine  
	$retMessage="OK - Dati ricevuti con successo\n $destinationPath";  
      }  
      else  
      {  
	// se c'è stato un problema  
	$retMessage="ERRORE! difficoltà nel muovere il file nella posizione indicata";  
      }
    }else{
      $retMessage="OK - Dati ricevuti con successo\n";  
    }
  }else{
    $retMessage="ERRORE! Problema nella ricezione dei dati dalla post";
    //echo ini_get('upload_max_filesize');
    //print_r($_FILES);
  }
  
  return $retMessage;
}

?>