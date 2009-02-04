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

class ux_t3lib_extFileFunctions extends t3lib_extFileFunctions{

      var $nodes=Array();
      var $distributedServiceUrl="/index.php?eID=cluster_worker";
      var $estimateSpeed="200";

      
      function ux_t3lib_extFileFunctions(){
            
            
            if ($GLOBALS["TYPO3_CONF_VARS"]['SYS']['nodes']){
                  $local_ip=$_SERVER['SERVER_ADDR'];
                  foreach ($GLOBALS["TYPO3_CONF_VARS"]['SYS']['nodes'] as $data){
                        if(gethostbyname($data['host']) != $local_ip){
                              $this->nodes[]=$data["host"];
                        }
                  }
                  
                  
      	      
            }
            
      }
      	/*************************************
	 *
	 * File operation functions for distributed systems
	 *
	 **************************************/

	/**
	 * Deleting files and folders (action=4)
	 *
	 * @param	array		$cmds['data'] is the file/folder to delete
	 * @return	boolean		Returns true upon success
	 */
	function func_delete($cmds)	{
		if (!$this->isInit) return FALSE;

			// Checking path:
		$theFile = $cmds['data'];
		if (!$this->isPathValid($theFile))	{
			$this->writelog(4,2,101,'Target "%s" had invalid path (".." and "//" is not allowed in path).',Array($theFile));
			return FALSE;
		}

			// Recycler moving or not?
		if ($this->useRecycler && $recyclerPath=$this->findRecycler($theFile))	{
				// If a recycler is found, the deleted items is moved to the recycler and not just deleted.
			$newCmds=Array();
			$newCmds['data']=$theFile;
			$newCmds['target']=$recyclerPath;
			$newCmds['altName']=1;
			$this->func_move($newCmds);
			$this->writelog(4,0,4,'Item "%s" moved to recycler at "%s"',Array($theFile,$recyclerPath));
			return TRUE;
		} elseif ($this->useRecycler != 2) {	// if $this->useRecycler==2 then we cannot delete for real!!
			if (@is_file($theFile))	{	// If we are deleting a file...
				if ($this->actionPerms['deleteFile'])	{
					if ($this->checkPathAgainstMounts($theFile))	{
						if (@unlink($theFile))	{
							$this->writelog(4,0,1,'File "%s" deleted',Array($theFile));
                                          $params["action"]="deleteFile";
                                          $params["destination"]=$theFile;
                                          $this->sendRequestToNodes($params);
							return TRUE;
						} else $this->writelog(4,1,110,'Could not delete file "%s". Write-permission problem?', Array($theFile));
					} else $this->writelog(4,1,111,'Target was not within your mountpoints! T="%s"',Array($theFile));
				} else $this->writelog(4,1,112,'You are not allowed to delete files','');
				// FINISHED deleting file

			} elseif (@is_dir($theFile)) {	// if we're deleting a folder
				if ($this->actionPerms['deleteFolder'])	{
					$theFile = $this->is_directory($theFile);
					if ($theFile)	{
						if ($this->checkPathAgainstMounts($theFile))	{	// I choose not to append '/' to $theFile here as this will prevent us from deleting mounts!! (which makes sense to me...)
							if ($this->actionPerms['deleteFolderRecursively'] && !$this->dont_use_exec_commands)	{
								if (t3lib_div::rmdir($theFile,true))	{
                                                      $params["action"]="deleteDir";
                                                      $params["directory"]=$theFile;
                                                      $params["r"]=1;
                                                      $this->sendRequestToNodes($params);
									$this->writelog(4,0,2,'Directory "%s" deleted recursively!',Array($theFile));
									return TRUE;
								} else $this->writelog(4,2,119,'Directory "%s" WAS NOT deleted recursively! Write-permission problem?',Array($theFile));
							} else {
								if (@rmdir($theFile))	{
                                                      $params["action"]="deleteDir";
                                                      $params["directory"]=$theFile;
                                                      $params["r"]=0;
                                                      $this->sendRequestToNodes($params);
									$this->writelog(4,0,3,'Directory "%s" deleted',Array($theFile));
									return TRUE;
								} else $this->writelog(4,1,120,'Could not delete directory! Write-permission problem? Is directory "%s" empty? (You are not allowed to delete directories recursively).',Array($theFile));
							}
						} else $this->writelog(4,1,121,'Target was not within your mountpoints! T="%s"',Array($theFile));
					} else $this->writelog(4,2,122,'Target seemed not to be a directory! (Shouldn\'t happen here!)','');
				} else $this->writelog(4,1,123,'You are not allowed to delete directories','');
				// FINISHED copying directory

			} else $this->writelog(4,2,130,'The item was not a file or directory! "%s"',Array($theFile));
		} else $this->writelog(4,1,131,'No recycler found!','');
	}

	/**
	 * Copying files and folders (action=2)
	 *
	 * @param	array		$cmds['data'] is the file/folder to copy. $cmds['target'] is the path where to copy to. $cmds['altName'] (boolean): If set, another filename is found in case the target already exists
	 * @return	string		Returns the new filename upon success
	 */
	function func_copy($cmds)	{
		if (!$this->isInit) return FALSE;

			// Initialize and check basic conditions:
		$theFile = $cmds['data'];
		$theDest = $this->is_directory($cmds['target']);	// Clean up destination directory
		$altName = $cmds['altName'];
		if (!$theDest)	{
			$this->writelog(2,2,100,'Destination "%s" was not a directory',Array($cmds['target']));
			return FALSE;
		}
		if (!$this->isPathValid($theFile) || !$this->isPathValid($theDest))	{
			$this->writelog(2,2,101,'Target or destination had invalid path (".." and "//" is not allowed in path). T="%s", D="%s"',Array($theFile,$theDest));
			return FALSE;
		}

			// Processing of file or directory.
		if (@is_file($theFile))	{	// If we are copying a file...
			if ($this->actionPerms['copyFile'])	{
				if (filesize($theFile) < ($this->maxCopyFileSize*1024))	{
					$fI = t3lib_div::split_fileref($theFile);
					if ($altName)	{	// If altName is set, we're allowed to create a new filename if the file already existed
						$theDestFile = $this->getUniqueName($fI['file'], $theDest);
						$fI = t3lib_div::split_fileref($theDestFile);
					} else {
						$theDestFile = $theDest.'/'.$fI['file'];
					}
					if ($theDestFile && !@file_exists($theDestFile))	{
						if ($this->checkIfAllowed($fI['fileext'], $theDest, $fI['file'])) {
							if ($this->checkPathAgainstMounts($theDestFile) && $this->checkPathAgainstMounts($theFile))	{
								if ($this->PHPFileFunctions)	{
									copy ($theFile,$theDestFile);
								} else {
									$cmd = 'cp "'.$theFile.'" "'.$theDestFile.'"';
									exec($cmd);
								}
								t3lib_div::fixPermissions($theDestFile);
                                                
								clearstatcache();
								if (@is_file($theDestFile))	{
									$this->writelog(2,0,1,'File "%s" copied to "%s"',Array($theFile,$theDestFile));
                                                      $params["action"]="copyFile";
                                                      $params["sourceFile"]=$theFile;
                                                      $params["destinationFile"]=$theDestFile;
                                                      $this->sendRequestToNodes($params);
									return $theDestFile;
								} else $this->writelog(2,2,109,'File "%s" WAS NOT copied to "%s"! Write-permission problem?',Array($theFile,$theDestFile));
							} else	$this->writelog(2,1,110,'Target or destination was not within your mountpoints! T="%s", D="%s"',Array($theFile,$theDestFile));
						} else $this->writelog(2,1,111,'Extension of file name "%s" is not allowed in "%s"!',Array($fI['file'],$theDest.'/'));
					} else $this->writelog(2,1,112,'File "%s" already exists!',Array($theDestFile));
				} else $this->writelog(2,1,113,'File "%s" exceeds the size-limit of %s bytes',Array($theFile,$this->maxCopyFileSize*1024));
			} else $this->writelog(2,1,114,'You are not allowed to copy files','');
			// FINISHED copying file

		} elseif (@is_dir($theFile) && !$this->dont_use_exec_commands) {		// if we're copying a folder
			if ($this->actionPerms['copyFolder'])	{
				$theFile = $this->is_directory($theFile);
				if ($theFile)	{
					$fI = t3lib_div::split_fileref($theFile);
					if ($altName)	{	// If altName is set, we're allowed to create a new filename if the file already existed
						$theDestFile = $this->getUniqueName($fI['file'], $theDest);
						$fI = t3lib_div::split_fileref($theDestFile);
					} else {
						$theDestFile = $theDest.'/'.$fI['file'];
					}
					if ($theDestFile && !@file_exists($theDestFile))	{
						if (!t3lib_div::isFirstPartOfStr($theDestFile.'/',$theFile.'/'))	{			// Check if the one folder is inside the other or on the same level... to target/dest is the same?
							if ($this->checkIfFullAccess($theDest) || $this->is_webPath($theDestFile)==$this->is_webPath($theFile))	{	// no copy of folders between spaces
								if ($this->checkPathAgainstMounts($theDestFile) && $this->checkPathAgainstMounts($theFile))	{
										// No way to do this under windows!
									$cmd = 'cp -R "'.$theFile.'" "'.$theDestFile.'"';
									exec($cmd);
									clearstatcache();
									if (@is_dir($theDestFile))	{
                                                            $params["action"]="copyDir";
                                                            $params["sourceDir"]=$theFile;
                                                            $params["destinationDir"]=$theDestFile;
                                                            $this->sendRequestToNodes($params);
										$this->writelog(2,0,2,'Directory "%s" copied to "%s"',Array($theFile,$theDestFile));
										return $theDestFile;
									} else $this->writelog(2,2,119,'Directory "%s" WAS NOT copied to "%s"! Write-permission problem?',Array($theFile,$theDestFile));
								} else $this->writelog(2,1,120,'Target or destination was not within your mountpoints! T="%s", D="%s"',Array($theFile,$theDestFile));
							} else $this->writelog(2,1,121,'You don\'t have full access to the destination directory "%s"!',Array($theDest.'/'));
						} else $this->writelog(2,1,122,'Destination cannot be inside the target! D="%s", T="%s"',Array($theDestFile.'/',$theFile.'/'));
					} else $this->writelog(2,1,123,'Target "%s" already exists!',Array($theDestFile));
				} else $this->writelog(2,2,124,'Target seemed not to be a directory! (Shouldn\'t happen here!)','');
			} else $this->writelog(2,1,125,'You are not allowed to copy directories','');
			// FINISHED copying directory

		} else {
			$this->writelog(2,2,130,'The item "%s" was not a file or directory!',Array($theFile));
		}
	}

	/**
	 * Moving files and folders (action=3)
	 *
	 * @param	array		$cmds['data'] is the file/folder to move. $cmds['target'] is the path where to move to. $cmds['altName'] (boolean): If set, another filename is found in case the target already exists
	 * @return	string		Returns the new filename upon success
	 */
	function func_move($cmds)	{
		if (!$this->isInit) return FALSE;

			// Initialize and check basic conditions:
		$theFile = $cmds['data'];
		$theDest = $this->is_directory($cmds['target']);	// Clean up destination directory
		$altName = $cmds['altName'];
		if (!$theDest)	{
			$this->writelog(3,2,100,'Destination "%s" was not a directory',Array($cmds['target']));
			return FALSE;
		}
		if (!$this->isPathValid($theFile) || !$this->isPathValid($theDest))	{
			$this->writelog(3,2,101,'Target or destination had invalid path (".." and "//" is not allowed in path). T="%s", D="%s"',Array($theFile,$theDest));
			return FALSE;
		}

			// Processing of file or directory:
		if (@is_file($theFile))	{	// If we are moving a file...
			if ($this->actionPerms['moveFile'])	{
				if (filesize($theFile) < ($this->maxMoveFileSize*1024))	{
					$fI = t3lib_div::split_fileref($theFile);
					if ($altName)	{	// If altName is set, we're allowed to create a new filename if the file already existed
						$theDestFile = $this->getUniqueName($fI['file'], $theDest);
						$fI = t3lib_div::split_fileref($theDestFile);
					} else {
						$theDestFile = $theDest.'/'.$fI['file'];
					}
					if ($theDestFile && !@file_exists($theDestFile))	{
						if ($this->checkIfAllowed($fI['fileext'], $theDest, $fI['file'])) {
							if ($this->checkPathAgainstMounts($theDestFile) && $this->checkPathAgainstMounts($theFile))	{
								if ($this->PHPFileFunctions)	{
									rename($theFile, $theDestFile);
								} else {
									$cmd = 'mv "'.$theFile.'" "'.$theDestFile.'"';
									exec($cmd);
								}
								clearstatcache();
								if (@is_file($theDestFile))	{
									$this->writelog(3,0,1,'File "%s" moved to "%s"',Array($theFile,$theDestFile));
                                                      $params["action"]="moveFile";
                                                      $params["sourceFile"]=$theFile;
                                                      $params["destinationFile"]=$theDestFile;
                                                      $this->sendRequestToNodes($params);
									return $theDestFile;
								} else $this->writelog(3,2,109,'File "%s" WAS NOT moved to "%s"! Write-permission problem?',Array($theFile,$theDestFile));
							} else $this->writelog(3,1,110,'Target or destination was not within your mountpoints! T="%s", D="%s"',Array($theFile,$theDestFile));
						} else $this->writelog(3,1,111,'Extension of file name "%s" is not allowed in "%s"!',Array($fI['file'],$theDest.'/'));
					} else $this->writelog(3,1,112,'File "%s" already exists!',Array($theDestFile));
				} else $this->writelog(3,1,113,'File "%s" exceeds the size-limit of %s bytes',Array($theFile,$this->maxMoveFileSize*1024));
			} else $this->writelog(3,1,114,'You are not allowed to move files','');
			// FINISHED moving file

		} elseif (@is_dir($theFile)) {	// if we're moving a folder
			if ($this->actionPerms['moveFolder'])	{
				$theFile = $this->is_directory($theFile);
				if ($theFile)	{
					$fI = t3lib_div::split_fileref($theFile);
					if ($altName)	{	// If altName is set, we're allowed to create a new filename if the file already existed
						$theDestFile = $this->getUniqueName($fI['file'], $theDest);
						$fI = t3lib_div::split_fileref($theDestFile);
					} else {
						$theDestFile = $theDest.'/'.$fI['file'];
					}
					if ($theDestFile && !@file_exists($theDestFile))	{
						if (!t3lib_div::isFirstPartOfStr($theDestFile.'/',$theFile.'/'))	{			// Check if the one folder is inside the other or on the same level... to target/dest is the same?
							if ($this->checkIfFullAccess($theDest) || $this->is_webPath($theDestFile)==$this->is_webPath($theFile))	{	// // no moving of folders between spaces
								if ($this->checkPathAgainstMounts($theDestFile) && $this->checkPathAgainstMounts($theFile))	{
									if ($this->PHPFileFunctions)	{
										rename($theFile, $theDestFile);
									} else {
										$cmd = 'mv "'.$theFile.'" "'.$theDestFile.'"';
										$errArr = array();
										$retVar = 0;
										exec($cmd,$errArr,$retVar);
									}
									clearstatcache();
									if (@is_dir($theDestFile))	{
										$this->writelog(3,0,2,'Directory "%s" moved to "%s"',Array($theFile,$theDestFile));
                                                            $params["action"]="moveDir";
                                                            $params["sourceDir"]=$theFile;
                                                            $params["destinationDir"]=$theDestFile;
                                                            $this->sendRequestToNodes($params);
										return $theDestFile;
									} else $this->writelog(3,2,119,'Directory "%s" WAS NOT moved to "%s"! Write-permission problem?',Array($theFile,$theDestFile));
								} else $this->writelog(3,1,120,'Target or destination was not within your mountpoints! T="%s", D="%s"',Array($theFile,$theDestFile));
							} else $this->writelog(3,1,121,'You don\'t have full access to the destination directory "%s"!',Array($theDest.'/'));
						} else $this->writelog(3,1,122,'Destination cannot be inside the target! D="%s", T="%s"',Array($theDestFile.'/',$theFile.'/'));
					} else $this->writelog(3,1,123,'Target "%s" already exists!',Array($theDestFile));
				} else $this->writelog(3,2,124,'Target seemed not to be a directory! (Shouldn\'t happen here!)','');
			} else $this->writelog(3,1,125,'You are not allowed to move directories','');
			// FINISHED moving directory

		} else {
			$this->writelog(3,2,130,'The item "%s" was not a file or directory!',Array($theFile));
		}
	}

	/**
	 * Renaming files or foldes (action=5)
	 *
	 * @param	array		$cmds['data'] is the new name. $cmds['target'] is the target (file or dir).
	 * @return	string		Returns the new filename upon success
	 */
	function func_rename($cmds)	{
		if (!$this->isInit) return FALSE;

		$theNewName = $this->cleanFileName($cmds['data']);
		if ($theNewName)	{
			if ($this->checkFileNameLen($theNewName))	{
				$theTarget = $cmds['target'];
				$type = filetype($theTarget);
				if ($type=='file' || $type=='dir')	{		// $type MUST BE file or dir
					$fileInfo = t3lib_div::split_fileref($theTarget);		// Fetches info about path, name, extention of $theTarget
					if ($fileInfo['file']!=$theNewName)	{	// The name should be different from the current. And the filetype must be allowed
						$theRenameName = $fileInfo['path'].$theNewName;
						if ($this->checkPathAgainstMounts($fileInfo['path']))	{
							if (!@file_exists($theRenameName))	{
								if ($type=='file')	{
									if ($this->actionPerms['renameFile'])	{
										$fI = t3lib_div::split_fileref($theRenameName);
										if ($this->checkIfAllowed($fI['fileext'], $fileInfo['path'], $fI['file'])) {
											if (@rename($theTarget, $theRenameName))	{
												$this->writelog(5,0,1,'File renamed from "%s" to "%s"',Array($fileInfo['file'],$theNewName));
                                                                        $params["action"]="moveFile";
                                                                        $params["sourceFile"]=$theTarget;
                                                                        $params["destinationFile"]=$theRenameName;
                                                                        $this->sendRequestToNodes($params);
												return $theRenameName;
											} else $this->writelog(5,1,100,'File "%s" was not renamed! Write-permission problem in "%s"?',Array($theTarget,$fileInfo['path']));
										} else $this->writelog(5,1,101,'Extension of file name "%s" was not allowed!',Array($fI['file']));
									} else $this->writelog(5,1,102,'You are not allowed to rename files!','');
								} elseif ($type=='dir')	{
									if ($this->actionPerms['renameFolder'])	{
										if (@rename($theTarget, $theRenameName))	{
											$this->writelog(5,0,2,'Directory renamed from "%s" to "%s"',Array($fileInfo['file'],$theNewName));
                                                                  $params["action"]="moveDir";
                                                                  $params["sourceDir"]=$theTarget;
                                                                  $params["destinationDir"]=$theRenameName;
                                                                  $this->sendRequestToNodes($params);
											return $theRenameName;
										} else $this->writelog(5,1,110,'Directory "%s" was not renamed! Write-permission problem in "%s"?',Array($theTarget,$fileInfo['path']));
									} else $this->writelog(5,1,111,'You are not allowed to rename directories!','');
								}
							} else $this->writelog(5,1,120,'Destination "%s" existed already!',Array($theRenameName));
						} else $this->writelog(5,1,121,'Destination path "%s" was not within your mountpoints!',Array($fileInfo['path']));
					} else $this->writelog(5,1,122,'Old and new name is the same (%s)',Array($theNewName));
				} else $this->writelog(5,2,123,'Target "%s" was neither a directory nor a file!',Array($theTarget));
			} else $this->writelog(5,1,124,'New name "%s" was too long (max %s characters)',Array($theNewName,$this->maxInputNameLen));
		}
	}

	/**
	 * This creates a new folder. (action=6)
	 *
	 * @param	array		$cmds['data'] is the foldername. $cmds['target'] is the path where to create it.
	 * @return	string		Returns the new foldername upon success
	 */
	function func_newfolder($cmds)	{
		if (!$this->isInit) return FALSE;

		$theFolder = $this->cleanFileName($cmds['data']);
		if ($theFolder)	{
			if ($this->checkFileNameLen($theFolder))	{
				$theTarget = $this->is_directory($cmds['target']);	// Check the target dir
				if ($theTarget)	{
					if ($this->actionPerms['newFolder'])	{
						$theNewFolder = $theTarget.'/'.$theFolder;
						if ($this->checkPathAgainstMounts($theNewFolder))	{
							if (!@file_exists($theNewFolder))	{
								if (t3lib_div::mkdir($theNewFolder)){
                                                      $params["action"]="newDir";
                                                      $params["directory"]=$theNewFolder;
                                                      $this->sendRequestToNodes($params);
									$this->writelog(6,0,1,'Directory "%s" created in "%s"',Array($theFolder,$theTarget.'/'));
									return $theNewFolder;
								} else $this->writelog(6,1,100,'Directory "%s" not created. Write-permission problem in "%s"?',Array($theFolder,$theTarget.'/'));
							} else $this->writelog(6,1,101,'File or directory "%s" existed already!',Array($theNewFolder));
						} else $this->writelog(6,1,102,'Destination path "%s" was not within your mountpoints!',Array($theTarget.'/'));
					} else $this->writelog(6,1,103,'You are not allowed to create directories!','');
				} else $this->writelog(6,2,104,'Destination "%s" was not a directory',Array($cmds['target']));
			} else $this->writelog(6,1,105,'New name "%s" was too long (max %s characters)',Array($theFolder,$this->maxInputNameLen));
		}
	}

	/**
	 * This creates a new file. (action=8)
	 *
	 * @param	array		$cmds['data'] is the new filename. $cmds['target'] is the path where to create it
	 * @return	string		Returns the new filename upon success
	 */
	function func_newfile($cmds)	{
		$extList = $GLOBALS['TYPO3_CONF_VARS']['SYS']['textfile_ext'];
		if (!$this->isInit) return FALSE;
		$newName = $this->cleanFileName($cmds['data']);
		if ($newName)	{
			if ($this->checkFileNameLen($newName))	{
				$theTarget = $this->is_directory($cmds['target']);	// Check the target dir
				$fileInfo = t3lib_div::split_fileref($theTarget);		// Fetches info about path, name, extention of $theTarget
				if ($theTarget)	{
					if ($this->actionPerms['newFile'])	{
						$theNewFile = $theTarget.'/'.$newName;
						if ($this->checkPathAgainstMounts($theNewFile))	{
							if (!@file_exists($theNewFile))	{
								$fI = t3lib_div::split_fileref($theNewFile);
								if ($this->checkIfAllowed($fI['fileext'], $fileInfo['path'], $fI['file'])) {
									if (t3lib_div::inList($extList, $fI['fileext']))	{
										if (t3lib_div::writeFile($theNewFile,''))	{
											clearstatcache();
											$this->writelog(8,0,1,'File created: "%s"',Array($fI['file']));
                                                                  $params["action"]="newFile";
                                                                  $params["file"]="@$theNewFile";
                                                                  $params["destination"]=$theNewFile;
                                                                  $this->sendRequestToNodes($params);
											return $theNewFile;
										} else $this->writelog(8,1,100,'File "%s" was not created! Write-permission problem in "%s"?',Array($fI['file'], $theTarget));
									} else $this->writelog(8,1,107,'Fileextension "%s" is not a textfile format! (%s)',Array($fI['fileext'], $extList));
								} else $this->writelog(8,1,106,'Extension of file name "%s" was not allowed!',Array($fI['file']));
							} else $this->writelog(8,1,101,'File "%s" existed already!',Array($theNewFile));
						} else $this->writelog(8,1,102,'Destination path "%s" was not within your mountpoints!',Array($theTarget.'/'));
					} else $this->writelog(8,1,103,'You are not allowed to create files!','');
				} else $this->writelog(8,2,104,'Destination "%s" was not a directory',Array($cmds['target']));
			} else $this->writelog(8,1,105,'New name "%s" was too long (max %s characters)',Array($newName,$this->maxInputNameLen));
		}
	}

	/**
	 * Editing textfiles or folders (action=9)
	 *
	 * @param	array		$cmds['data'] is the new content. $cmds['target'] is the target (file or dir)
	 * @return	boolean		Returns true on success
	 */
	function func_edit($cmds)	{
		if (!$this->isInit) return FALSE;
		$theTarget = $cmds['target'];
		$content = $cmds['data'];
		$extList = $GLOBALS['TYPO3_CONF_VARS']['SYS']['textfile_ext'];
		$type = filetype($theTarget);
		if ($type=='file')	{		// $type MUST BE file
			$fileInfo = t3lib_div::split_fileref($theTarget);		// Fetches info about path, name, extention of $theTarget
			$fI =$fileInfo;
			if ($this->checkPathAgainstMounts($fileInfo['path']))	{
				if ($this->actionPerms['editFile'])	{
					$fI = t3lib_div::split_fileref($theTarget);
					if ($this->checkIfAllowed($fI['fileext'], $fileInfo['path'], $fI['file'])) {
						if (t3lib_div::inList($extList, $fileInfo['fileext']))	{
							if (t3lib_div::writeFile($theTarget,$content))	{
								clearstatcache();
								$this->writelog(9,0,1,'File saved to "%s", bytes: %s, MD5: %s ',Array($fileInfo['file'],@filesize($theTarget),md5($content)));
                                                $params["action"]="editFile";
                                                $params["destination"]=$theTarget;
                                                $params["content"]=$content;
                                                $this->sendRequestToNodes($params);
								return TRUE;
							} else $this->writelog(9,1,100,'File "%s" was not saved! Write-permission problem in "%s"?',Array($theTarget,$fileInfo['path']));
						} else $this->writelog(9,1,102,'Fileextension "%s" is not a textfile format! (%s)',Array($fI['fileext'], $extList));
					} else $this->writelog(9,1,103,'Extension of file name "%s" was not allowed!',Array($fI['file']));
				} else $this->writelog(9,1,104,'You are not allowed to edit files!','');
			} else $this->writelog(9,1,121,'Destination path "%s" was not within your mountpoints!',Array($fileInfo['path']));
		} else $this->writelog(9,2,123,'Target "%s" was not a file!',Array($theTarget));
	}

	/**
	 * Upload of files (action=1)
	 *
	 * @param	array		$cmds['data'] is the ID-number (points to the global var that holds the filename-ref  ($_FILES['upload_'.$id]['name']). $cmds['target'] is the target directory
	 * @return	string		Returns the new filename upon success
	 */
	function func_upload($cmds)	{
            
		if (!$this->isInit) return FALSE;
		$id = $cmds['data'];
		if ($_FILES['upload_'.$id]['name'])	{
                  $theFile = $_FILES['upload_'.$id]['tmp_name'];				// filename of the uploaded file
			$theFileSize = $_FILES['upload_'.$id]['size'];				// filesize of the uploaded file
			$theName = $this->cleanFileName(stripslashes($_FILES['upload_'.$id]['name']));	// The original filename
			if (is_uploaded_file($theFile) && $theName)	{	// Check the file
				if ($this->actionPerms['uploadFile'])	{
					if ($theFileSize<($this->maxUploadFileSize*1024))	{
						$fI = t3lib_div::split_fileref($theName);
						$theTarget = $this->is_directory($cmds['target']);	// Check the target dir
						if ($theTarget && $this->checkPathAgainstMounts($theTarget.'/'))	{
							if ($this->checkIfAllowed($fI['fileext'], $theTarget, $fI['file'])) {
								$theNewFile = $this->getUniqueName($theName, $theTarget, $this->dontCheckForUnique);
								if ($theNewFile)	{
                                                      
									t3lib_div::upload_copy_move($theFile,$theNewFile);
									clearstatcache();
									if (@is_file($theNewFile))	{
										$this->internalUploadMap[$id] = $theNewFile;
										$this->writelog(1,0,1,'Uploading file "%s" to "%s"',Array($theName,$theNewFile, $id));
                                                            $params["action"]="uploadFile";
                                                            $params["file"]="@$theNewFile";
                                                            $params["destination"]="$theNewFile";
                                                            $this->sendRequestToNodes($params);
                                                            return $theNewFile;
									} else $this->writelog(1,1,100,'Uploaded file could not be moved! Write-permission problem in "%s"?',Array($theTarget.'/'));
								} else $this->writelog(1,1,101,'No unique filename available in "%s"!',Array($theTarget.'/'));
							} else $this->writelog(1,1,102,'Extension of file name "%s" is not allowed in "%s"!',Array($fI['file'], $theTarget.'/'));
						} else $this->writelog(1,1,103,'Destination path "%s" was not within your mountpoints!',Array($theTarget.'/'));
					} else $this->writelog(1,1,104,'The uploaded file exceeds the size-limit of %s bytes',Array($this->maxUploadFileSize*1024));
				} else $this->writelog(1,1,105,'You are not allowed to upload files!','');
			} else $this->writelog(1,2,106,'The upload has failed, no uploaded file found!','');
		} else $this->writelog(1,2,108,'No file was uploaded!','');
	}
      


	/**
	 * Unzipping file (action=7)
	 * This is permitted only if the user has fullAccess or if the file resides
	 *
	 * @param	array		$cmds['data'] is the zip-file. $cmds['target'] is the target directory. If not set we'll default to the same directory as the file is in.
	 * @return	boolean		Returns true on success
	 */
	function func_unzip($cmds)	{
		if (!$this->isInit || $this->dont_use_exec_commands) return FALSE;

		$theFile = $cmds['data'];
		if (@is_file($theFile))	{
			$fI = t3lib_div::split_fileref($theFile);
			if (!isset($cmds['target']))	{
				$cmds['target'] = $fI['path'];
			}
			$theDest = $this->is_directory($cmds['target']);	// Clean up destination directory
			if ($theDest)	{
				if ($this->actionPerms['unzipFile'])	{
					if ($fI['fileext']=='zip')	{
						if ($this->checkIfFullAccess($theDest)) {
							if ($this->checkPathAgainstMounts($theFile) && $this->checkPathAgainstMounts($theDest.'/'))	{
									// No way to do this under windows.
								$cmd = $this->unzipPath.'unzip -qq "'.$theFile.'" -d "'.$theDest.'"';
								exec($cmd);
								$this->writelog(7,0,1,'Unzipping file "%s" in "%s"',Array($theFile,$theDest));
								return TRUE;
							} else $this->writelog(7,1,100,'File "%s" or destination "%s" was not within your mountpoints!',Array($theFile,$theDest));
						} else $this->writelog(7,1,101,'You don\'t have full access to the destination directory "%s"!',Array($theDest));
					} else $this->writelog(7,1,102,'Fileextension is not "zip"','');
				} else $this->writelog(7,1,103,'You are not allowed to unzip files','');
			} else $this->writelog(7,2,104,'Destination "%s" was not a directory',Array($cmds['target']));
		} else $this->writelog(7,2,105,'The file "%s" did not exist!',Array($theFile));
	}
      
      /**
            Send request to nodes for modified file(new,updated,move,deleted,...)
            @param      array       $params:parameters of the request     
            */
      function sendRequestToNodes($params){
            $timeout=ceil(($GLOBALS["TYPO3_CONF_VARS"]["BE"]["maxFileSize"])/$this->estimateSpeed);
            $params['typo3_cluster_execute']=1;
            $handlers=Array();
            $connects=curl_multi_init();
            foreach($this->nodes as $node_ip){
                  $connect=curl_init();
                  $url="http://".$node_ip.$this->distributedServiceUrl;
                  curl_setopt($connect, CURLOPT_TIMEOUT,$timeout);
                  curl_setopt($connect, CURLOPT_URL, $url);
                  curl_setopt($connect, CURLOPT_FOLLOWLOCATION, 0);
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
            foreach($handlers as $index=>$curl){
                  $content=curl_multi_getcontent($curl);
                  if (!stristr($content,"OK")){
                        $this->sendAlert("Error node {$this->nodes[$index]} returned: $content");
                        //$this->writelog(0,1,0,"Error in {$this->nodes[$index]}: ".$content,Array());
                  }
            }
      }
      
      /**
	 * Send an alert to the admin email
	 * @param 	string		$message: the alert message
	 * @return	void
	 */
	function sendAlert($server,$message){
	    $subject = "Alert from $server";
	    $email = $GLOBALS['TYPO3_CONF_VARS']['BE']['warning_email_addr'];
	    if(trim($email)){
	      t3lib_div::plainMailEncoded($email,$subject,$message,'','quoted-printable','',false);
	    }
	}
}

if (defined('TYPO3_MODE') && $TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/typo3_cluster/class.ux_t3lib_extfilefunc.php'])    {
    include_once($TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/typo3_cluster/class.ux_t3lib_extfilefunc.php']);
}



?>