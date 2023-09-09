<?php
/*
 * @author Till Steinbach <till.steinbach@gmx.de>
 * @modified Christian Bartsch <cb AT dreinulldrei DOT de> and SkyhawkXava
 * @copyright (c) Till Steinbach
 * @license GPL v2
 * @date 2020-01-08
 */

require_once __DIR__ . '/config/general.config.inc.php';
require_once __DIR__ . '/config/directory.config.inc.php';
require_once __DIR__ . '/locale/' . $language . '/directory.locale.inc.php';
require_once __DIR__ . '/lib/logging/logging.php';
require_once __DIR__ . '/lib/cipxml/cipxml.php';

use logging\logFile;
use cipxml\CiscoIPPhoneDirectory;
use cipxml\CiscoIPPhoneMenu;
use cipxml\CiscoIPPhoneText;
use cipxml\CiscoIPPhoneInput;
use cipxml\DirectoryEntry;
use cipxml\InputItem;
use cipxml\InputFlags;
use cipxml\MenuItem;
use cipxml\SoftKeyItem;
use cipxml\KeyItem;
use cipxml\Key;

header("Content-type: text/xml");

$translation = array("home" => PB_FIELD_HOME, "mobile" => PB_FIELD_MOBILE, "work" => PB_FIELD_WORK, "fax" => PB_FIELD_FAX, "fax_work" => PB_FIELD_FAX_WORK, "private" => PB_FIELD_PRIVATE, "business" => PB_FIELD_BUSINESS, "other" => PB_FIELD_OTHER);

$log = new logFile ($logging_activated, $logging_format, $logging_path);
$log->newEntry ("directory.php: started");

if(isset($_GET["refresh"])) {
	$log->newEntry ("directory.php: execute: refresh");
	if (is_writable("books/") AND function_exists('curl_init') AND function_exists('mb_convert_encoding')) {
		$log->newEntry ("directory.php: execute: refresh > /books is writable");
		if (!$runon_Fritzbox) {
			$log->newEntry ("directory.php: execute: refresh > webserver is not running on Fritz!Box");
			$fritzbox_cfg = 'http://' . $fritzbox_ip . '/cgi-bin/firmwarecfg';
			$ch = curl_init('http://' . $fritzbox_ip . '/login_sid.lua');
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($ch, CURLOPT_FAILONERROR, true);
			$login = curl_exec($ch);

			if (curl_errno($ch)) {
			    $log->newEntry ("directory.php: execute: refresh > ERROR: ".curl_error($ch));
			}

			if (((bool)$ch===false) OR (curl_getinfo($ch, CURLINFO_RESPONSE_CODE)!=200)) {
				if (curl_getinfo($ch, CURLINFO_RESPONSE_CODE) != null) {
					$http_response_code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
				} else {
					$html_response_code = "n/a";
				}
				$log->newEntry ("directory.php: execute: refresh > ERROR: HTTP-CODE ".$html_response_code." => ".curl_getinfo($ch, CURLINFO_EFFECTIVE_URL));
			} else {
				$session_status_simplexml = simplexml_load_string($login);
				$log->newEntry ("directory.php: execute: refresh > SID={$session_status_simplexml->SID}");
				if ($session_status_simplexml->SID != '0000000000000000') {
					$SID = $session_status_simplexml->SID;
				} else {
					$challenge = $session_status_simplexml->Challenge;
					$response = $challenge . '-' . md5(mb_convert_encoding($challenge . '-' . $fritzbox_password, "UCS-2LE", "UTF-8"));
					if (isset($fritzbox_user)) {
						$log->newEntry ("directory.php: execute: refresh > Username is set to '{$fritzbox_user}'");
						$StringCURLOptPostfields = "&username={$fritzbox_user}&response={$response}&page=/login_sid.lua";
					} else {
						$log->newEntry ("directory.php: execute: refresh > Username is not set");
						$StringCURLOptPostfields = "response={$response}&page=/login_sid.lua";
					}
					$log->newEntry ("directory.php: execute: refresh > CURLOPT_POSTFIELDS={$StringCURLOptPostfields}");

					curl_setopt($ch, CURLOPT_POSTFIELDS, $StringCURLOptPostfields);
					$sendlogin = curl_exec($ch);
					$session_status_simplexml = simplexml_load_string($sendlogin);
					$log->newEntry ("directory.php: execute: refresh > SID={$session_status_simplexml->SID}");
					
					if ($session_status_simplexml->SID != '0000000000000000'){
						$log->newEntry ("directory.php: execute: refresh > Login successful");
						$SID = $session_status_simplexml->SID;
					} else {
						$log->newEntry ("directory.php: execute: refresh > Login failed");
						$menu = new CiscoIpPhoneText(PB_REFRESH, PB_NAME_GENERAL . ' ' . PB_LOGIN_FAILED, PB_ADMIN_CHECKPWD);
						echo '<?xml version="1.0" encoding="utf-8" ?>';
						echo (string) $menu;

						curl_close($ch);
						return;
					}
				}

				$clear_books = true;
				$tmp_telefonbuch = $telefonbuch;

				do {
					$log->newEntry ("directory.php: execute: refresh > try to download phonebook: id=".$tmp_telefonbuch);
					curl_setopt($ch, CURLOPT_URL, $fritzbox_cfg);
					curl_setopt($ch, CURLOPT_POSTFIELDS, array("sid" => $SID, "PhonebookId" => $tmp_telefonbuch, "PhonebookExportName" => "Telefonbuch", "PhonebookExport" => ""));
					$book = curl_exec($ch);
					if(empty($book)) {
						$log->newEntry ("directory.php: execute: refresh > phonebook id=".$tmp_telefonbuch." - ERROR: response is empty - download-process is stopped. Please check permission");
						$menu = new CiscoIpPhoneText(PB_REFRESH, PB_NAME_GENERAL . ' ' . PB_BOOKS_ERROR, PB_ADMIN_CHECKPERM);
						echo '<?xml version="1.0" encoding="utf-8" ?>';
						echo (string) $menu; 

						curl_close($ch);
						return;
					}
					$xml = simplexml_load_string($book);
					if ((bool) $xml === false) { // catch error!
						foreach(libxml_get_errors() as $error) {
							$log->newEntry ("directory.php: execute: refresh > phonebook id=".$tmp_telefonbuch." - ERROR: ". $error->message);
						}

						$menu = new CiscoIpPhoneText(PB_REFRESH, PB_NAME_GENERAL . ' ' . PB_BOOKS_ERROR, PB_NO_FURTHER_INFORMATION);
						echo '<?xml version="1.0" encoding="utf-8" ?>';
						echo (string) $menu;

						curl_close($ch);
						return;
					}

					if(!$xml->phonebook) {
						if($tmp_telefonbuch < 240) { // no more local books, jump to external books
							$tmp_telefonbuch = 240;
							continue;
						}else if($tmp_telefonbuch < 255) { // read every external book + internal phones
							$tmp_telefonbuch++;
							continue;
						}else{
							$log->newEntry ("directory.php: execute: refresh > phonebook id=".$tmp_telefonbuch." does not exist - download-process is stopped");
							
							break;
						}
					}

					if($clear_books) { // keep old downloads on errors - until first new arrives
						foreach(scandir("books") as $old_book){
							if(is_file("books/$old_book") && strpos($book,'.xml') !== false){
								unlink("books/$old_book");
							}
						}
						$clear_books = false;
					}

					if ((bool) file_put_contents("books/$tmp_telefonbuch.xml",$book, LOCK_EX)) {
						$log->newEntry ("directory.php: execute: refresh > phonebook id=".$tmp_telefonbuch." saved");
					} else {
						$log->newEntry ("directory.php: execute: refresh > ERROR: could not save phonebook id=".$tmp_telefonbuch);
					}
					$tmp_telefonbuch++;
				} while (true);

				curl_close($ch);
			}

		} else {
			$log->newEntry ("directory.php: execute: refresh > webserver is running on Fritz!Box");
			do { // for Fritzboxes with webserver -> direct copy
				$log->newEntry ("directory.php: execute: refresh > try to export phonebook: id=".$tmp_telefonbuch);
				shell_exec("pbd --exportbook " . $tmp_telefonbuch);
				// shell_exec("cat /tmp/pbd.export > " . FRITZBOX_LOCAL_PATH . $tmp_telefonbuch . ".xml");
				// if (!file_exists(FRITZBOX_LOCAL_PATH . $tmp_telefonbuch . ".xml")) {
				//	break;
				// }
				if (!copy("/tmp/pbd.export", FRITZBOX_LOCAL_PATH . $tmp_telefonbuch . ".xml")) {
					// distinction below is untested!
					if($tmp_telefonbuch < 240) { // no more local books, jump to external books
						$tmp_telefonbuch = 240;
						continue;
					}else if($tmp_telefonbuch < 255) { // read every external book + internal phones
						$tmp_telefonbuch++;
						continue;
					}else{
						$log->newEntry ("directory.php: execute: refresh > phonebook id=".$tmp_telefonbuch." does not exist - export-process is stopped");

						break;
					}
				}
				$tmp_telefonbuch++;
			} while (true);
		}

		header('Expires: ' . gmdate('D, d M Y H:i:s', time()-60*60) . ' GMT');
	} else {
		$error_msg = "";

		// ERROR: No rights to write	
		if(!is_writable("books/")) {
			$log->newEntry ("directory.php: execute: refresh > '/books' is not writeable");
			$error_msg = PB_BOOKS_ADMIN_CHECK_FOLDER_RIGHTS;
		}

		// ERROR: The extensioin/module 'libcurl' is not available
		if (!function_exists('curl_init')) {
			$log->newEntry ("directory.php: execute: refresh > the extension/module 'libcurl' is not available");
			if (strlen($error_msg) > 0) {
				$error_msg .= "\r\n";
			}
			$error_msg .= PB_BOOKS_ADMIN_CHECK_LIBCURL;
		}

		// ERROR: The extension/module 'mbstring' is not available
		if (!function_exists('mb_convert_encoding')) {
			$log->newEntry ("directory.php: execute: refresh > the extension/module 'mbstring' is not available");
			if (strlen($error_msg) > 0) {
				$error_msg .= "\r\n";
			}
			$error_msg .= PB_BOOKS_ADMIN_CHECK_MBSTRING;
		}

		$menu = new CiscoIpPhoneText(PB_REFRESH, PB_NAME_GENERAL . ' ' . PB_BOOKS_ERROR, $error_msg);
		echo '<?xml version="1.0" encoding="utf-8" ?>';
		echo (string) $menu; 
		return;
	}
}

$has_books = false;
foreach(scandir("books") as $book){
	if(is_file("books/$book") && strpos($book,'.xml') !== false){
		$has_books=true;
		break;
	}
}
if ($has_books) {
	$log->newEntry ("directory.php: phonebooks exist");
} else {
	$log->newEntry ("directory.php: no phonebook exists");
}

if((!isset($_GET["book"]) && ($show_BookSelection)) or (!$has_books))
{
    $log->newEntry ("directory.php: execute: book");
    if($has_books){
        $menu = new CiscoIpPhoneMenu(PB_PHONEBOOKS, PB_SELECT_PHONEBOOK);

		if($show_MissedCalls){
            $menu->addMenuItem(new MenuItem(PB_APP_CALLSMISSED, 'Application:Cisco/MissedCalls'));
        }
        if($show_ReceivedCalls){
            $menu->addMenuItem(new MenuItem(PB_APP_CALLSRECEIVED, 'Application:Cisco/ReceivedCalls'));
        }
        if($show_MissedCalls){
            $menu->addMenuItem(new MenuItem(PB_APP_CALLSPLACED, 'Application:Cisco/PlacedCalls'));
        }

        foreach(scandir("books") as $book){
            if(is_file("books/$book") && strpos($book,'.xml') !== false){
               $input = file_get_contents("books/$book");
               $xml = simplexml_load_string($input);
               $attributes = $xml->phonebook->attributes();
			   $name = $attributes["name"];
			   if(empty($name)) { // names for built-in unnamed books
					if($book == "0.xml")  $name = PB_PHONEBOOK;
					else if($book == "255.xml")  $name = PB_INTERNAL;
			   }
               $name .= " (" . PB_NAME_GENERAL . ")";
               $get = $_GET;
               unset($get['refresh']);
               $url = "http://" . $_SERVER["SERVER_NAME"] . $_SERVER["PHP_SELF"] . '?' . http_build_query(array_merge($get,array("book"=>$book)));
               $menu->addMenuItem(new MenuItem($name, $url));
            }
        }
		if ($show_QuickDial) {
			if(!defined('QUICKDIAL_URL')) {
				define('QUICKDIAL_URL', $url = "http://" . $_SERVER["SERVER_NAME"]
				. substr($_SERVER["PHP_SELF"], 0, strrpos($_SERVER["PHP_SELF"], '/')) . '/quickdial.php');
			}
          	$menu->addMenuItem(new MenuItem(QUICKDIAL_NAME, QUICKDIAL_URL));
		}
		
        $menu->addSoftKeyItem(new SoftKeyItem(PB_BUTTON_SELECT, 1, 'SoftKey:Select'));
		$menu->addSoftKeyItem(new SoftKeyItem(PB_BUTTON_EXIT, 2, 'Init:Directories'));
        $url = 'http://' . $_SERVER['SERVER_NAME'] .  $_SERVER['PHP_SELF'] .  '?refresh';
        $menu->addSoftKeyItem(new SoftKeyItem(PB_BUTTON_REFRESH, 4, $url));
    }
    else{
        $menu = new CiscoIpPhoneText(PB_PHONEBOOKS, PB_NO_PHONEBOOKS, PB_NO_PHONEBOOKS_DESC);
        $menu->addSoftKeyItem(new SoftKeyItem(PB_BUTTON_EXIT, 1, 'Init:Directories'));
        $url = 'http://' . $_SERVER['SERVER_NAME'] .  $_SERVER['PHP_SELF'] .  '?refresh';
        $menu->addSoftKeyItem(new SoftKeyItem(PB_BUTTON_REFRESH, 4, $url));
        header('Expires: ' . gmdate('D, d M Y H:i:s', time()-60*60) . ' GMT');
    }
} else{
    if (isset($_GET["book"])) {
	  $tmp_book = $_GET["book"];
	} else {
	  $tmp_book = "$telefonbuch.xml";
	}
	
    $input = @file_get_contents("books/".$tmp_book);
	if($input === false) {
		$log->newEntry ("directory.php: execute: phonebook ".$tmp_book." not present");
		$menu = new CiscoIpPhoneText(PB_PHONEBOOK, PB_PHONEBOOK_UNAVAILABLE, PB_PHONEBOOK_UNAVAILABLE_DESC);
		$url = "http://" . $_SERVER["SERVER_NAME"] . $_SERVER["PHP_SELF"];
		if ($show_BookSelection) { 
			$menu->addSoftKeyItem(new SoftKeyItem(PB_BUTTON_BACK, 1, $url));
		} else {
			$menu->addSoftKeyItem(new SoftKeyItem(PB_BUTTON_EXIT, 1, 'Init:Directories'));
		}
		$url .= '?' . http_build_query(array_merge($_GET,array("refresh"=>true)));
		$menu->addSoftKeyItem(new SoftKeyItem(PB_BUTTON_REFRESH, 4, $url));
		echo '<?xml version="1.0" encoding="utf-8" ?>';
		echo (string) $menu; 

		return;
	}

    $xml = simplexml_load_string($input);

    if(isset($_GET["queryname"]) && strlen($_GET["queryname"])){
        for($i = count($xml->phonebook->contact)-1; $i >= 0; --$i){
            $name = $xml->phonebook->contact[$i]->person->realName;
            if(stripos($name, $_GET['queryname']) === false){
                $dom=dom_import_simplexml($xml->phonebook[0]->contact[$i]);
                $dom->parentNode->removeChild($dom);
            }
        }
    }
    if(isset($_GET["querynumber"]) && strlen($_GET["querynumber"])){
		$log->newEntry ("directory.php: execute: querynumber");
        for($i = count($xml->phonebook->contact)-1; $i >= 0; --$i){
            if($xml->phonebook->contact[$i]->telephony){
                for($j = count($xml->phonebook->contact[$i]->telephony->number)-1; $j >= 0; --$j){
                    $number =  preg_replace('/[^0-9+*#]/', '', $xml->phonebook->contact[$i]->telephony->number[$j]);
					if($tmp_book == "255.xml")  $number = "**" . $number; // prefix for internal phones
                    if(stripos($number, $_GET['querynumber']) !== false){
                        continue 2; // move on to next contact
                    }
                }

				$dom=dom_import_simplexml($xml->phonebook->contact[$i]);
				$dom->parentNode->removeChild($dom);
            }
        }
    }

    if(!isset($_GET["id"])){
		$log->newEntry ("directory.php: execute: no id");
		if(!isset($_GET["search"])){
            // header('Expires: ' . gmdate('D, d M Y H:i:s', time()-60*60) . ' GMT');
            $offset = 0;
            if(isset($_GET["offset"])){
                $offset = (int) $_GET["offset"];
            }
            $attributes = $xml->phonebook->attributes();

            if(count($xml->phonebook->contact)>0){
				$name = $attributes["name"];
				if(empty($name)) { // names for built-in unnamed books
						if($tmp_book == "0.xml")  $name = PB_PHONEBOOK;
						else if($tmp_book == "255.xml")  $name = PB_INTERNAL;
				}
                $menu = new CiscoIpPhoneMenu(PB_NAME_GENERAL . ' ' . PB_PHONEBOOK, $name);
                for ($i = $offset; $i < count($xml->phonebook->contact) && $i<$offset+30; ++$i){ 
                    $name = $xml->phonebook->contact[$i]->person->realName;
					$get = $_GET;
					unset($get['refresh']);
					$url = "http://" . $_SERVER["SERVER_NAME"] .  $_SERVER["PHP_SELF"] . '?' . http_build_query($get) . "&id=" . $i;
                    $menu->addMenuItem(new MenuItem($name, $url));
                }
                $menu->addSoftKeyItem(new SoftKeyItem(PB_BUTTON_SELECT, 1, 'SoftKey:Select'));

				if ($show_BookSelection) { 
					$url = "http://" . $_SERVER["SERVER_NAME"] . $_SERVER["PHP_SELF"];
					$menu->addSoftKeyItem(new SoftKeyItem(PB_BUTTON_BACK, 3, $url));
				} else {
					$menu->addSoftKeyItem(new SoftKeyItem(PB_BUTTON_EXIT, 3, 'Init:Directories'));
				}
				$get = $_GET;
				unset($get['refresh']);
                $url = "http://" . $_SERVER["SERVER_NAME"] . $_SERVER["PHP_SELF"] . '?' . http_build_query(array_merge($get,array("search"=>true)));
                $menu->addSoftKeyItem(new SoftKeyItem(PB_BUTTON_SEARCH, 2, $url));
 
				unset($get['offset']);
				$tmp_url = "http://" . $_SERVER["SERVER_NAME"] . $_SERVER["PHP_SELF"] . '?' . http_build_query($get);
 
                if($offset>0){
                    $newoffset = $offset-30;
                    if($newoffset<0){
                        $newoffset=0;
                    }
                    $url = $tmp_url . "&offset=" .  $newoffset;
                    $menu->addSoftKeyItem(new SoftKeyItem(PB_BUTTON_PREVIOUS_PAGE, 3, $url));
					$menu->addKeyItem(new KeyItem(Key::NavLeft,$url));
                }
                if(($offset+30)<count($xml->phonebook->contact)){
                    $url = $tmp_url . "&offset=" .  ($offset+30);
                    $menu->addSoftKeyItem(new SoftKeyItem(PB_BUTTON_NEXT_PAGE, 4, $url));
					$menu->addKeyItem(new KeyItem(Key::NavRight,$url));
                } else {
				    $url = 'http://' . $_SERVER['SERVER_NAME'] .  $_SERVER['PHP_SELF'] .  '?' . http_build_query(array_merge($_GET,array("refresh"=>true)));
					$menu->addSoftKeyItem(new SoftKeyItem(PB_BUTTON_REFRESH, 4, $url));
				}
				
            }
            else{
                $menu = new CiscoIpPhoneText(PB_NAME_GENERAL . ' ' . PB_PHONEBOOK, PB_NO_ENTRIES, PB_NO_ENTRIES_DESC);
                $menu->addSoftKeyItem(new SoftKeyItem(PB_BUTTON_BACK, 1, 'SoftKey:Exit'));
            }
        }
        else{
            $get = $_GET;
			unset($get['refresh']);
            unset($get['search']);
            unset($get['queryname']);
            unset($get['querynumber']);
            $url = "http://" . $_SERVER["SERVER_NAME"] . $_SERVER["PHP_SELF"] . '?' . http_build_query($get);
            $menu = new CiscoIpPhoneInput(PB_NAME_GENERAL . ' ' . PB_PHONEBOOK, PB_INPUT_QUERY, $url);
            if(isset($_GET['queryname'])){
                $queryname = $_GET['queryname'];
            }
            else{
                $queryname="";
            }
            if(isset($_GET['querynumber'])){
                $querynumber = $_GET['querynumber'];
            }
            else{
                $querynumber="";
            }

            $menu->addInputItem(new InputItem(PB_INPUT_NAME, 'queryname', InputFlags::U, $queryname));
            $menu->addInputItem(new InputItem(PB_INPUT_NUMBER, 'querynumber', InputFlags::T, $querynumber));
        }
    }
    else{
        $log->newEntry ("directory.php: execute: id");
        $id = (int) $_GET["id"];
        $name = $xml->phonebook->contact[$id]->person->realName;
        if(strlen($name)>32){
            $name = substr($xml->phonebook->contact[$id]->person->realName,0,29) . "...";
        }
        if(!isset($_GET["details"])){
            $menu = new CiscoIpPhoneDirectory(PB_NAME_GENERAL . ' ' . PB_PHONEBOOK, $name);
            for ($i = 0; $i < count($xml->phonebook->contact[$id]->telephony->number); ++$i){
                $attributes = $xml->phonebook->contact[$id]->telephony->number[$i]->attributes();
                $number = preg_replace('/[^0-9+*#]/', '', $xml->phonebook->contact[$id]->telephony->number[$i]);
				if($tmp_book == "255.xml")  $number = "**" . $number; // prefix for internal phones
				$type = (string) $attributes["type"];
                $label = "Sonstige";
                if(array_key_exists($type, $translation)){
                    $label = $translation[$type];
                }
                else if(strstr($type,"label:")){
                    $label = substr($type,strlen("label:"));
                }
                $menu->addDirectoryEntry(new DirectoryEntry($label, $number));
            }
			$menu->addSoftKeyItem(new SoftKeyItem(PB_BUTTON_DIAL, 1, 'SoftKey:Dial'));
			$get = $_GET;
			unset($get['refresh']);
			$url = "http://" . $_SERVER["SERVER_NAME"] . $_SERVER["PHP_SELF"] . '?' . http_build_query($get) . "&details";
            $menu->addSoftKeyItem(new SoftKeyItem(PB_BUTTON_DETAILS, 4, $url));
			unset($get['id']);
            $url = "http://" . $_SERVER["SERVER_NAME"] . $_SERVER["PHP_SELF"] . '?' . http_build_query($get);
            $menu->addSoftKeyItem(new SoftKeyItem(PB_BUTTON_BACK, 3, $url));

        }
        else{
            $text = PB_NO_FURTHER_INFORMATION;
			if(isset($xml->phonebook->contact[$id]->services->email) && count($xml->phonebook->contact[$id]->services->email)){
                $text = PB_FIELD_EMAIL . ":\n";
                for ($i = 0; $i < count($xml->phonebook->contact[$id]->services->email); ++$i){
                    $attributes = $xml->phonebook->contact[$id]->services->email[$i]->attributes();
                    $type = (string) $attributes["classifier"];
                    $label = PB_FIELD_OTHER;
                    if(array_key_exists($type, $translation)){
                        $label = $translation[$type];
                    }
                    else if(strstr($type,"label:")){
                        $label = substr($type,strlen("label:"));
                    }
                    $text.=$label . ': ';
                    $text.=$xml->phonebook->contact[$id]->services->email[$i]."\n";
                }
            }
            $menu = new CiscoIpPhoneText(PB_NAME_GENERAL . ' ' . PB_PHONEBOOK, $name, $text);
			$get = $_GET;
			unset($get['refresh']);
            unset($get['details']);
            // $url = "http://" . $_SERVER["SERVER_NAME"] . $_SERVER["PHP_SELF"] . '?' . http_build_query($get);
            $menu->addSoftKeyItem(new SoftKeyItem(PB_BUTTON_BACK, 3, 'SoftKey:Exit'));
        }
    }
}
echo (string) $menu;
?>

