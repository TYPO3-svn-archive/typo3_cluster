<?php
$url="";
$postdata="";
$f=fopen("/var/log/lighttpd/helper.log","a");

foreach($argv as $arg){
            
      if (strstr($arg,"--post-data")){
            $postdata=str_replace("--post-data=","",$arg);
            $postdata=trim(rawurldecode($postdata));
            
      }else if (strstr($arg,"--url")){
            $url=str_replace("--url=","",$arg);
            
            $url=trim(rawurldecode($url));
            }
      }
      //fwrite($f,"url=".$url."\n");
//fwrite($f,"postdata=".$postdata."\n\n\n");
            $connect=curl_init();                        
            curl_setopt($connect, CURLOPT_TIMEOUT,50);
            curl_setopt($connect, CURLOPT_URL, $url);
            curl_setopt($connect, CURLOPT_FOLLOWLOCATION, 0);
            curl_setopt($connect, CURLOPT_MAXREDIRS,10);
            curl_setopt($connect, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($connect, CURLOPT_HTTPHEADER, array('Expect:'));
            curl_setopt($connect, CURLOPT_POST, true);
            curl_setopt($connect, CURLOPT_POSTFIELDS, $postdata);
            curl_setopt($connect, CURLOPT_ENCODING, "");			 
            $res=curl_exec($connect);
            
            fwrite($f,$res."\n");
            fclose($f);
            //(curl_error($connect));
            
                                                                                    
                                                                                    ?>