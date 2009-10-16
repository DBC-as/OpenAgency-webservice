<?php
/**
 *   
 * This file is part of openAgency.
 * Copyright © 2009, Dansk Bibliotekscenter a/s, 
 * Tempovej 7-11, DK-2750 Ballerup, Denmark. CVR: 15149043
 *
 * openAgency is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * openAgency is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with openAgency.  If not, see <http://www.gnu.org/licenses/>.
*/


/** \brief openagency webservice server
 *
 */

require_once "OLS_class_lib/inifile_class.php";
require_once "OLS_class_lib/curl_class.php";
require_once "OLS_class_lib/cql2solr_class.php";
require_once "OLS_class_lib/verbose_class.php";
require_once "OLS_class_lib/timer_class.php";
require_once "OLS_class_lib/oci_class.php";

// create timer and define timer format
$timer = new stopwatch("", " ", "", "%s:%01.3f");

// Fetch ini file and Check for needed settings
define("INIFILE", "openagency.ini");
$config = new inifile(INIFILE);
if ($config->error)
  usage($config->error);

// some constants
define("WSDL", $config->get_value("wsdl", "setup"));
define("AGENCY_CREDENTIALS", $config->get_value("agency_credentials", "setup"));

// for logging
$verbose = new verbose($config->get_value("logfile", "setup"), 
                       $config->get_value("verbose", "setup"));

// Essentials
if (!constant("WSDL"))               usage("No WSDL defined in " . INIFILE);
if (!constant("AGENCY_CREDENTIALS")) usage("No database defined in " . INIFILE);

// environment ok, ready and eager to serve


// surveil check?
if (isset($_GET["HowRU"]))	how_am_i($config);


// Look for a request. SOAP or REST or test from browser
if (!isset($HTTP_RAW_POST_DATA))
  if (!$HTTP_RAW_POST_DATA =  get_REST_request($config))
    if (!$HTTP_RAW_POST_DATA = stripslashes($_REQUEST["request"])) {
       $debug_req = $config->get_value("debug_request", "debug");
       $debug_info = $config->get_value("debug_info", "debug");
    } 

if (empty($HTTP_RAW_POST_DATA) && empty($debug_req))	
  usage("No input data found");


// Request found
if ($HTTP_RAW_POST_DATA) {
  $verbose->log(TRACE, $HTTP_RAW_POST_DATA);

  $request_obj = soap_to_obj($HTTP_RAW_POST_DATA);
  $request_body = &$request_obj->{'Envelope'}->{'Body'};
  $action = key($request_body);
//var_dump($request_body); var_dump($request_body->$action); die();
  $request = $request_body->$action;
  if ($request->outputType) {
    $request_handler = new request_handler();
    $res = $request_handler->$action($request);
    switch ($request->outputType) {
      case "json":
        if ($request->callback)
          echo $request->callback . " && " . $request->callback . "(" . json_encode($res) . ")";
        else
          echo json_encode($res);
        break;
      case "php":
        echo serialize($res);
        break;
      case "xml":
        //header("Content-Type: text/xml");
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . 
             array_to_xml($res);
        break;
      default:
        echo "Unknown outputType: " . $request->outputType;
    }
  } else {
    $server = new SoapServer(WSDL, array("cache_wsdl" => WSDL_CACHE_NONE));
    //$server = new SoapServer(WSDL);

    $server->setClass('request_handler');
    $server->handle();
  }

  $verbose->log(TIMER, $timer->dump());
} elseif ($debug_req) {
  echo '<html><head><title>Test openagency</title></head><body>' . echo_form($debug_req, $debug_info) . '</body></html>'; 
}



/* ------------------------------------------------- */

  /** \brief Handles the request and set up the response
   *
   * Sofar all records er matchet via the work-relation
   * if/when formats without this match is needed, the 
   * code must branch according to that
   *
   */
class request_handler {
	public function openAgencyAutomation($param) {
    global $verbose, $timer, $config;
    $oci = new Oci(AGENCY_CREDENTIALS);
    $oci->connect();
    if ($err = $oci->get_error_string()) {
      $verbose->log(ERROR, "OpenAgency:: OCI connect error: " . $err);
      return(array("error" => "service_unavailable"));
    }
    $res = array();
    switch ($param->autService) {
      case "autPotential":
        $oci->bind("bind_laantager", $param->agencyId);
        $oci->bind("bind_materiale_id", $param->materialType);
        $oci->set_query("SELECT id_nr, valg 
                         FROM vip_fjernlaan 
                         WHERE laantager = :bind_laantager 
                           AND materiale_id = :bind_materiale_id");
        $vf_row = $oci->fetch_into_assoc();
        if ($vf_row["VALG"] == "a") {
          $oci->bind("bind_materiale_id", $param->materialType);
          $oci->bind("bind_status", "J");
          $oci->set_query("SELECT laangiver 
                           FROM vip_fjernlaan 
                           WHERE materiale_id = :bind_materiale_id
                             AND status = :bind_status");    // ??? NULL og DISTINCT
          $res["materialType"] = $param->materialType;
          while ($vf_row = $oci->fetch_into_assoc())
            $res["responder"][] = $vf_row["LAANGIVER"];
        } elseif ($vf_row["VALG"] == "l") {
          $oci->bind("bind_fjernlaan_id", $vf_row["ID_NR"]);
          $oci->set_query("SELECT bib_nr 
                           FROM vip_fjernlaan_bibliotek
                           WHERE fjernlaan_id = :bind_fjernlaan_id");
          $res["materialType"] = $param->materialType;
          while ($vfb_row = $oci->fetch_into_assoc())
            $res["responder"][] = $vfb_row["BIB_NR"];
        } else
          return(array("error" => "no_agencies_found"));
        break;
      case "autRequester":
        $oci->bind("bind_laantager", $param->agencyId);
        $oci->bind("bind_materiale_id", $param->materialType);
        $oci->set_query("SELECT *
                         FROM vip_fjernlaan 
                         WHERE laantager = :bind_laantager 
                           AND materiale_id = :bind_materiale_id");
        if ($vf_row = $oci->fetch_into_assoc())
          $res = array("requester" => $vf_row["LAANTAGER"],
                       "materialType" => $vf_row["MATERIALE_ID"],
                       "willSend" => ($vf_row["STATUS"] == "J" ? "YES" : "NO"), 
                       "autPeriod" => $vf_row["PERIODE"],
                       "autId" => $vf_row["ID_NR"],
                       "autChoice" => $vf_row["VALG"],
                       "autRes" => ($vf_row["RESERVERING"] == "J" ? "YES" : "NO"));
        break;
      case "autProvider":
        $oci->bind("bind_laangiver", $param->agencyId);
        $oci->bind("bind_materiale_id", $param->materialType);
        $oci->set_query("SELECT *
                         FROM vip_fjernlaan 
                         WHERE laangiver = :bind_laangiver 
                           AND materiale_id = :bind_materiale_id");
        if ($vf_row = $oci->fetch_into_assoc())
          $res = array("provider" => $vf_row["LAANGIVER"],
                       "materialType" => $vf_row["MATERIALE_ID"],
                       "willReceive" => ($vf_row["STATUS"] == "J" ? "YES" : "NO"), 
                       "autPeriod" => $vf_row["PERIODE"],
                       "autId" => $vf_row["ID_NR"]);
        break;
      default: 
        return(array("error" => "error_in_request"));
    }
    var_dump($res);
    var_dump($param); die();
  }
	public function openAgencyEncryption($param) {
    global $verbose, $timer, $config;

    $oci = new Oci(AGENCY_CREDENTIALS);
    $oci->connect();
    if ($err = $oci->get_error_string()) {
      $verbose->log(ERROR, "OpenAgency:: OCI connect error: " . $err);
      return(array("error" => "service_unavailable"));
    }
    $res = array();
    $oci->bind("bind_email", $param->email);
    $oci->set_query("SELECT * FROM vip_krypt WHERE email = :bind_email");
    while ($vk_row = $oci->fetch_into_assoc())
      $res[] = array("encryption" => 
                 array("email" => $param->email,
                       "agencyId" => $vk_row["BIBLIOTEK"],
                       "key" => $vk_row["KEY"],
                       "base64" => ($vk_row["NOTBASE64"] == "ja" ? "NO" : "YES"),
                       "date" => $vk_row["UDL_DATO"]));
    print_r($res);
    var_dump($param); die();

    if (empty($res)) 
      return(array("error" => "no_agencies_found"));

    return $res;
  }
	public function openAgencyService($param) {    // ???? param->service
    global $verbose, $timer, $config;

    $oci = new Oci(AGENCY_CREDENTIALS);
    $oci->connect();
    if ($err = $oci->get_error_string()) {
      $verbose->log(ERROR, "OpenAgency:: OCI connect error: " . $err);
      return(array("error" => "service_unavailable"));
    }
    $res = array();
    $tab_col["v"] = array("bib_nr", "navn", "tlf_nr", "fax_nr", "email", "badr", "bpostnr", "bcity", "type", "*");
    $tab_col["vv"] = array("bib_nr", "navn", "tlf_nr", "fax_nr", "email", "badr", "bpostnr", "bcity", "bib_type", "*");
    $tab_col["vd"] = array("bib_nr", "*");
    $tab_col["vk"] = array("bib_nr", "*");
    $tab_col["oao"] = array("bib_nr", "*");
    foreach ($tab_col as $prefix => $arr)
      foreach ($arr as $col)
        $q .= (empty($q) ? "" : ", ") . 
              $prefix . '.' . $col . 
              ($col == "*" ? "" : ' "' . strtoupper($prefix . '.' . $col) . '"');
    $oci->bind("bind_id_nr", $param->agencyId);
    $oci->set_query("SELECT " . $q . "
                     FROM vip v, vip_vsn vv, vip_danbib vd, vip_kat vk, open_agency_ors oao 
                     WHERE v.bib_nr = vd.bib_nr (+)
                       AND v.bib_vsn = vv.bib_nr 
                       AND v.bib_nr = vk.bib_nr 
                       AND v.bib_nr = oao.bib_nr (+)
                       AND v.bib_nr = :bind_id_nr");
    $oa_row = $oci->fetch_into_assoc();
    switch ($param->service) {
			case "information":
        $res = array(
          "information" => array(
          "branchId" => $oa_row["V.BIB_NR"],
          "agencyId" => $oa_row["VV.BIB_NR"],
          "agencyName" => $oa_row["VV.NAVN"],
          "agencyPhone" => $oa_row["VV.TLF_NR"],
          "agencyFax" => $oa_row["VV.FAX_NR"],
          "agencyEmail" => $oa_row["VV.EMAIL"],
          "agencyType" => $oa_row["VV.BIB_TYPE"],
          "branchName" => $oa_row["V.NAVN"],
          "branchPhone" => $oa_row["V.TLF_NR"],
          "branchFax" => $oa_row["V.FAX_NR"],
          "branchEmail" => $oa_row["V.EMAIL"],
          "branchType" => $oa_row["V.TYPE"],
          "postalAddress" => $oa_row["V.BADR"],
          "postalCode" => $oa_row["V.BPOSTNR"],
          "city" => $oa_row["V.BCITY"],
          "isil" => $oa_row["ISIL"],
          "junction" => $oa_row["KNUDEPUNKT"],
          "kvik" => ($oa_row["KVIK"] == "kvik" ? "YES" : "NO"),
          "norfri" => ($oa_row["NORFRI"] == "norfri" ? "YES" : "NO"),
          "sender" => $oa_row["CHANGE_REQUESTER"]));
        break;
			case "orsAnswer":
        $res = array(
          "orsAnswer" => array(
          "responder" => $oa_row["OAO.BIB_NR"],
          "willReceive" => (in_array($oa_row["ANSWER"], array("z3950", "mail", "ors")) ? "YES" : ""),
          "protocol" => $oa_row["ANSWER"],
          "address" => "",
          "userId" => $oa_row["ANSWER_Z3950_USER"],
          "groupId" => $oa_row["ANSWER_Z3950_GROUP"],
          "passWord" => ($oa_row["ANSWER"] == "z3950" ? $oa_row["ANSWER_Z3950_PASSWORD"] : $oa_row["ANSWER_NCIP_AUTH"])));
          if ($oa_row["ANSWER"] == "z3950") 
            $res["orsAnswer"]["address"] = $oa_row["ANSWER_Z3950_ADDRESS"];
          elseif ($oa_row["ANSWER"] == "mail") 
            $res["orsAnswer"]["address"] = $oa_row["ANSWER_MAIL_ADDRESS"];
        break;
			case "orsCancelRequestUser":
        $res = array(
          "orsCancelRequestUser" => array(
          "responder" => $oa_row["VK.BIB_NR"],
          "willReceive" => ($oa_row["NCIP_CANCEL"] == "J" ? "YES" : "NO"),
          "address" => $oa_row["NCIP_CANCEL_ADDRESS"],
          "passWord" => $oa_row["NCIP_CANCEL_PASSWORD"]));
        break;
			case "orsEndUserRequest":
        $res = array(
          "orsEndUserRequest" => array(             // ????
          "responder" => $oa_row["OAO.BIB_NR"],
          "willReceive" => (in_array($oa_row["ANSWER"], array("z3950", "mail", "ors")) ? "YES" : ""),
          "protocol" => $oa_row["ANSWER"],
          "address" => "",
          "userId" => $oa_row["ANSWER_Z3950_USER"],
          "groupId" => $oa_row["ANSWER_Z3950_GROUP"],
          "passWord" => ($oa_row["ANSWER"] == "z3950" ? $oa_row["ANSWER_Z3950_PASSWORD"] : $oa_row["ANSWER_NCIP_AUTH"]),
          "format" => $oa_row["ANSWER_MAIL_FORMAT"]));
          if ($oa_row["ANSWER"] == "z3950") 
            $res["orsEndUserRequest"]["address"] = $oa_row["ANSWER_Z3950_ADDRESS"];
          elseif ($oa_row["ANSWER"] == "mail") 
            $res["orsEndUserRequest"]["address"] = $oa_row["ANSWER_MAIL_ADDRESS"];
        break;
			case "orsItemRequest":
        $res = array(
          "orsItemRequest" => array(
          "responder" => $oa_row["OAO.BIB_NR"],
          "willReceive" => (in_array($oa_row["REQUEST"], array("z3950", "mail", "ors")) ? "YES" : ""),
          "protocol" => $oa_row["REQUEST"],
          "address" => "",
          "userId" => $oa_row["REQUEST_Z3950_USER"],
          "groupId" => $oa_row["REQUEST_Z3950_GROUP"],
          "passWord" => ($oa_row["REQUEST"] == "z3950" ? $oa_row["REQUEST_Z3950_PASSWORD"] : $oa_row["REQUEST_NCIP_AUTH"]),
          "format" => ($oa_row["REQUEST"] == "mail" ? $oa_row["REQUEST_MAIL_FORMAT"] : "")));
          if ($oa_row["REQUEST"] == "z3950") 
            $res["orsItemRequest"]["address"] = $oa_row["REQUEST_Z3950_ADDRESS"];
          elseif ($oa_row["REQUEST"] == "mail") 
            $res["orsItemRequest"]["address"] = $oa_row["REQUEST_MAIL_ADDRESS"];
        break;
			case "orsLookupUser":
        $res = array(
          "orsLookupUser" => array(
          "responder" => $oa_row["VK.BIB_NR"],
          "willReceive" => ($oa_row["NCIP_LOOKUP_USER"] == "J" ? "YES" : "NO"),
          "address" => $oa_row["NCIP_LOOKUP_USER_ADDRESS"],
          "passWord" => $oa_row["NCIP_LOOKUP_USER_PASSWORD"]));
        break;
			case "orsReceipt":
        $res = array(
          "orsReceipt" => array(
          "responder" => $oa_row["OAO.BIB_NR"],
          "willReceive" => (in_array($oa_row["RECEIPT"], array("mail", "ors")) ? "YES" : ""),
          "protocol" => $oa_row["RECEIPT"],
          "address" => $oa_row["RECEIPT_MAIL_ADRESS"],
          "format" => $oa_row["RECEIPT_MAIL_FORMAT"]));
        break;
			case "orsRenewItemUser":
        $res = array(
          "orsRenewItemUser" => array(
          "responder" => $oa_row["VK.BIB_NR"],
          "willReceive" => ($oa_row["NCIP_RENEW"] == "J" ? "YES" : "NO"),
          "address" => $oa_row["NCIP_RENEW_ADDRESS"],
          "passWord" => $oa_row["NCIP_RENEW_PASSWORD"]));
        break;
			case "orsShipping":
        $res = array(
			  	"orsShipping" => array(
          "responder" => $oa_row["OAO.BIB_NR"],
          "willReceive" => (in_array($oa_row["SHIPPING"], array("z3950", "mail", "ors")) ? "YES" : ""),
          "protocol" => $oa_row["SHIPPING"],
          "address" => "",
          "userId" => $oa_row["SHIPPING_Z3950_USER"],
          "groupId" => $oa_row["SHIPPING_Z3950_GROUP"],
          "passWord" => ($oa_row["SHIPPING"] == "z3950" ? $oa_row["SHIPPING_Z3950_PASSWORD"] : $oa_row["SHIPPING_NCIP_AUTH"])));
          if ($oa_row["SHIPPING"] == "z3950") 
            $res["orsShipping"]["address"] = $oa_row["SHIPPING_Z3950_ADDRESS"];
        break;
			case "serverInformation":
        $res = array(
          "serverInformation" => array(
          "responder" => $oa_row["VD.BIB_NR"],
          "isil" => $oa_row["ISIL"],
          "address" => $oa_row["URL_ITEMORDER_BESTIL"],
          "userId" => $oa_row["ZBESTIL_USERID"],
          "groupId" => $oa_row["ZBESTIL_GROUPID"],
          "passWord" => $oa_row["ZBESTIL_PASSW"]));
        break;
      default: 
        return(array("error" => "error_in_request"));
    }



    print_r($res);
    var_dump($param); die();
  }
	public function openAgencyNameList($param) {
    global $verbose, $timer, $config;

    $oci = new Oci(AGENCY_CREDENTIALS);
    $oci->connect();
    if ($err = $oci->get_error_string()) {
      $verbose->log(ERROR, "OpenAgency:: OCI connect error: " . $err);
      return(array("error" => "service_unavailable"));
    }
    $res = array();
    //$param->libraryType;
    if ($param->libraryType == "Folkebibliotek" || 
        $param->libraryType == "Forskningsbibliotek") {
      $oci->bind("bind_bib_type", $param->libraryType);
      $add_bib_type = " WHERE bib_type = :bind_bib_type";
    } elseif (!empty($param->libraryType))
      return(array("error" => "error_in_request"));
    $oci->set_query("SELECT bib_nr, navn FROM vip_vsn" . $add_bib_type);
    while ($vv_row = $oci->fetch_into_assoc())
      $res["agency"][] = array("agencyId" => $vv_row["BIB_NR"], "agencyName" => $vv_row["NAVN"]);
    print_r($res);
    var_dump($param); die();
  }
	public function openAgencyProxyDomains($param) {  // ????
    global $verbose, $timer, $config;

    $oci = new Oci(AGENCY_CREDENTIALS);
    $oci->connect();
    if ($err = $oci->get_error_string()) {
      $verbose->log(ERROR, "OpenAgency:: OCI connect error: " . $err);
      return(array("error" => "service_unavailable"));
    }
    $res = array();
    //$oci->set_query("SELECT bib_nr, navn FROM vip_vsn");
    $res["domains"][] = array("domain" => array(),
                              "ip" => "",
                              "userId" => "",
                              "passWord" => "");
    print_r($res);
    var_dump($param); die();
  }
	public function openAgencyProxyIp($param) {  // ????
    global $verbose, $timer, $config;

    $oci = new Oci(AGENCY_CREDENTIALS);
    $oci->connect();
    if ($err = $oci->get_error_string()) {
      $verbose->log(ERROR, "OpenAgency:: OCI connect error: " . $err);
      return(array("error" => "service_unavailable"));
    }
    $res = array();
    //$oci->set_query("SELECT bib_nr, navn FROM vip_vsn");
    $res[] = array("ip" => "");
                                      
    print_r($res);
    var_dump($param); die();
  }
}

/* ------------------------------------------------- */

/** \brief Echoes a string, display usage info and die
 *
 */
function usage($str = "") {
  if ($str) echo $str . "<br/>";
	echo "Usage: ";
  die();
}

/** \brief Checks if needed components are available and responds
 *
 * 2do: This will be replaces by a test-class
 */
function how_am_i(&$config) {
  // Check solr

  // Checks done
  if (empty($err)) die("Gr8\n"); else die($err);
}

/** \brief Create a SOAP-object
 *
 */
function soap_to_obj(&$request) {
  $dom = new DomDocument();
  $dom->preserveWhiteSpace = false;
  if ($dom->loadXML($request))
    return xml_to_obj($dom);
}
function xml_to_obj($domobj) {
  //var_dump($domobj->nodeName);
  //echo "len: " . $domobj->domobj->childNodes->length;
  foreach ($domobj->childNodes as $node) {
    $nodename = $node->nodeName;
    if ($i = strpos($nodename, ":")) $nodename = substr($nodename, $i+1);
    if ($node->nodeName == "#text")
      $ret = $node->nodeValue;
    elseif (is_array($ret->{$node->nodeName}))
      $ret->{$nodename}[] = xml_to_obj($node);
    elseif (isset($ret->$nodename)) {
      $tmp = $ret->$nodename;
      unset($ret->$nodename);
      $ret->{$nodename}[] = $tmp;
      $ret->{$nodename}[] = xml_to_obj($node);
    } else
      $ret->$nodename = xml_to_obj($node);
  }

  return $ret;
}

/** \brief Creates xml from array. Numeric indices creates repetitive tags
 *
 */
function array_to_xml($arr) {
  if (is_scalar($arr))
    return htmlspecialchars($arr);
  elseif ($arr) {
    foreach ($arr as $key => $val)
      if (is_array($val) && is_numeric(array_shift(array_keys($val))))
        foreach ($val as $num_val)
          $ret .= tag_me($key, array_to_xml($num_val));
      else
        $ret .= tag_me($key, array_to_xml($val));
    return $ret;
  }
}

/** \brief Transform REST parameters to SOAP-request
 *
 */
function get_REST_request(&$config) {
  $action_pars = $config->get_value("action", "rest");
  if (is_array($action_pars) && $_GET["action"] && $action_pars[$_GET["action"]]) {
    if ($node_value = build_xml(&$action_pars[$_GET["action"]], explode("&", $_SERVER["QUERY_STRING"])))
      return html_entity_decode($config->get_value("soap_header", "rest")) . 
             tag_me($_GET["action"], $node_value) . 
             html_entity_decode($config->get_value("soap_footer", "rest"));
  }
}

function build_xml($action, $query) {
  foreach ($action as $key => $tag)
    if (is_array($tag))
      $ret .= tag_me($key, build_xml($tag, $query));
    else
      foreach ($query as $parval) {
        list($par, $val) = par_split($parval);
        if ($tag == $par) $ret .= tag_me($tag, $val);
      }
  return $ret;
}

function par_split($parval) {
  list($par, $val) = explode("=", $parval, 2);
  return array(preg_replace("/\[[^]]*\]/", "", urldecode($par)), $val);
}

function tag_me($tag, $val) {
//  if ($i = strrpos($tag, "."))
//    $tag = substr($tag, $i+1);
  return "<$tag>$val</$tag>"; 
}

/** \brief For browsertesting
 *
 */
function echo_form(&$reqs, $info="") {
  foreach ($reqs as $key => $req)
    $reqs[$key] = addcslashes(html_entity_decode($req), '"');

  $ret = '<script language="javascript">' . "\n" . 'reqs = Array("' . implode('","', $reqs) . '");</script>';
  $ret .= '<form name="f" method="post"><textarea rows="18" cols="80" name="request">' . stripslashes($_REQUEST["request"]) . '</textarea>';
  $ret .= '<br/><br/><select name="no" onChange="if (this.selectedIndex) document.f.request.value = reqs[this.options[this.selectedIndex].value];"><option>Pick a test-request</option>';
  foreach ($reqs as $key => $req)
    $ret .= '<option value="' . $key . '">Test request nr ' . $key . '</option>';
  $ret .= '</select> &nbsp; <input type="submit" name="subm" value="Try me">';
  $ret .= '</form>';
  return $ret . html_entity_decode($info);
}

?>
