<?php
/**
 *
 * This file is part of Open Library System.
 * Copyright © 2009, Dansk Bibliotekscenter a/s,
 * Tempovej 7-11, DK-2750 Ballerup, Denmark. CVR: 15149043
 *
 * Open Library System is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Open Library System is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with Open Library System.  If not, see <http://www.gnu.org/licenses/>.
*/


/** \brief
 *
 */


require_once("OLS_class_lib/webServiceServer_class.php");
require_once("OLS_class_lib/oci_class.php");

class openAgency extends webServiceServer {

 /** \brief
  *
  */
  function automation($param) {
    $oci = new Oci($this->config->get_value("agency_credentials","setup"));
    $oci->set_charset("UTF8");
    $oci->connect();
    if ($err = $oci->get_error_string()) {
      verbose::log(FATAL, "OpenAgency:: OCI connect error: " . $err);
      $res->error->_value = "service_unavailable";
    } else {
      $agency = $this->strip_agency($param->agencyId->_value);
      switch ($param->autService->_value) {
        case "autPotential":
          $oci->bind("bind_laantager", $agency);
          $oci->bind("bind_materiale_id", $param->materialType->_value);
          $oci->set_query("SELECT id_nr, valg
                           FROM vip_fjernlaan
                           WHERE laantager = :bind_laantager
                             AND materiale_id = :bind_materiale_id");
          $vf_row = $oci->fetch_into_assoc();
          if ($vf_row["VALG"] == "a") {
            $oci->bind("bind_materiale_id", $param->materialType->_value);
            $oci->bind("bind_status", "J");
            $oci->set_query("SELECT laangiver
                             FROM vip_fjernlaan
                             WHERE materiale_id = :bind_materiale_id
                               AND status = :bind_status");    // ??? NULL og DISTINCT
            $ap = &$res->autPotential->_value;
            $ap->materialType->_value = $param->materialType->_value;
            while ($vf_row = $oci->fetch_into_assoc())
              if ($vf_row["LAANGIVER"])
                $ap->responder[]->_value = $vf_row["LAANGIVER"];
          } elseif ($vf_row["VALG"] == "l") {
            $oci->bind("bind_fjernlaan_id", $vf_row["ID_NR"]);
            $oci->set_query("SELECT bib_nr
                             FROM vip_fjernlaan_bibliotek
                             WHERE fjernlaan_id = :bind_fjernlaan_id");
            $ap = &$res->autPotential->_value;
            $ap->materialType->_value = $param->materialType->_value;
            while ($vfb_row = $oci->fetch_into_assoc())
              $ap->responder[]->_value = $vfb_row["BIB_NR"];
          } else
            $res->error->_value = "no_agencies_found";
          break;
        case "autRequester":
          $oci->bind("bind_laantager", $agency);
          $oci->bind("bind_materiale_id", $param->materialType->_value);
          $oci->set_query("SELECT *
                           FROM vip_fjernlaan
                           WHERE laantager = :bind_laantager
                             AND materiale_id = :bind_materiale_id");
          if ($vf_row = $oci->fetch_into_assoc()) {
            $ar = &$res->autRequester->_value;
            $ar->requester->_value = $vf_row["LAANTAGER"];
            $ar->materialType->_value = $vf_row["MATERIALE_ID"];
            if ($vf_row["STATUS"] == "T")
              $ar->willSend->_value = "TEST";
            elseif ($vf_row["STATUS"] == "J")
              $ar->willSend->_value = "YES";
            else
              $ar->willSend->_value = "NO";
            $ar->autPeriod->_value = $vf_row["PERIODE"];
            $ar->autId->_value = $vf_row["ID_NR"];
            $ar->autChoice->_value = $vf_row["VALG"];
            $ar->autRes->_value = ($vf_row["RESERVERING"] == "J" ? "YES" : "NO");
          } else
            $res->error->_value = "no_agencies_found";
          break;
        case "autProvider":
          $oci->bind("bind_laangiver", $agency);
          $oci->bind("bind_materiale_id", $param->materialType->_value);
          $oci->set_query("SELECT *
                           FROM vip_fjernlaan
                           WHERE laangiver = :bind_laangiver
                             AND materiale_id = :bind_materiale_id");
          if ($vf_row = $oci->fetch_into_assoc()) {
            $ap = &$res->autProvider->_value;
            $ap->provider->_value = $vf_row["LAANGIVER"];
            $ap->materialType->_value = $vf_row["MATERIALE_ID"];
            $ap->willReceive->_value = ($vf_row["STATUS"] == "J" ? "YES" : "NO");
            $ap->autPeriod->_value = $vf_row["PERIODE"];
            $ap->autId->_value = $vf_row["ID_NR"];
          } else
            $res->error->_value = "no_agencies_found";
          break;
        default:
          $res->error->_value = "error_in_request";
      }
    }
    //var_dump($res); var_dump($param); die();
    $ret->automationResponse->_value = $res;
    return $ret;
  }

 /** \brief
  *
  */
  public function encryption($param) {
    $oci = new Oci($this->config->get_value("agency_credentials","setup"));
    $oci->set_charset("UTF8");
    $oci->connect();
    if ($err = $oci->get_error_string()) {
      verbose::log(FATAL, "OpenAgency:: OCI connect error: " . $err);
      $res->error->_value = "service_unavailable";
    } else {
      $oci->bind("bind_email", $param->email->_value);
      $oci->set_query("SELECT * FROM vip_krypt WHERE email = :bind_email");
      while ($vk_row = $oci->fetch_into_assoc()) {
        $o->email->_value = $param->email->_value;
        $o->agencyId->_value = $vk_row["BIBLIOTEK"];;
        $o->key->_value = $vk_row["KEY"];
        $o->base64->_value = ($vk_row["NOTBASE64"] == "ja" ? "NO" : "YES");
        $o->date->_value = $vk_row["UDL_DATO"];
        $res->encryption[]->_value = $o;
        unset($o);
      }
      if (empty($res))
        $res->error->_value = "no_agencies_found";
    }

    //var_dump($res); var_dump($param); die();
    $ret->encryptionResponse->_value = $res;
    return $ret;
  }
 /** \brief
  *
  */
  public function endUserOrderPolicy($param) {
    $oci = new Oci($this->config->get_value("agency_credentials","setup"));
    $oci->set_charset("UTF8");
    $oci->connect();
    if ($err = $oci->get_error_string()) {
      verbose::log(FATAL, "OpenAgency:: OCI connect error: " . $err);
      $res->error->_value = "service_unavailable";
    } else {
      $assoc["cdrom"]     = array("CDROM_BEST_MODT", "CDROM_BEST_MODT_FJL");
      $assoc["journal"]   = array("PER_BEST_MODT",   "PER_BEST_MODT_FJL");
      $assoc["monograph"] = array("MONO_BEST_MODT",  "MONO_BEST_MODT_FJL");
      $assoc["music"]     = array("MUSIK_BEST_MODT", "MUSIK_BEST_MODT_FJL");
      $assoc["newspaper"] = array("AVIS_BEST_MODT",  "AVIS_BEST_MODT_FJL");
      $assoc["video"]     = array("VIDEO_BEST_MODT", "VIDEO_BEST_MODT_FJL");
      if (strtolower($param->ownedByAgency->_value) == "true"
       || $param->ownedByAgency->_value == "1")
        $owned_by_agency = 0;
      elseif (strtolower($param->ownedByAgency->_value) == "false"
       || $param->ownedByAgency->_value == "0")
        $owned_by_agency = 1;
      if (isset($owned_by_agency) 
        && ($will_receive = $assoc[strtolower($param->orderMaterialType->_value)][$owned_by_agency])) {
        $agency = $this->strip_agency($param->agencyId->_value);
        $oci->bind("bind_bib_nr", $agency);
        $oci->set_query("SELECT " . $will_receive . " \"WR\" FROM vip_beh WHERE bib_nr = :bind_bib_nr");
        if ($vb_row = $oci->fetch_into_assoc())
          $res->willReceive->_value = ($vb_row["WR"] == "J" ? "true" : "false");
      } else
        $res->error->_value = "error_in_request";
    }
    if (empty($res))
      $res->error->_value = "no_agencies_found";

    //var_dump($res); var_dump($param); die();
    $ret->endUserOrderPolicyResponse->_value = $res;
    return $ret;
  }

 /** \brief
  *
  */
  public function service($param) {    // ???? param->service
    $oci = new Oci($this->config->get_value("agency_credentials","setup"));
    $oci->set_charset("UTF8");
    $oci->connect();
    if ($err = $oci->get_error_string()) {
      verbose::log(FATAL, "OpenAgency:: OCI connect error: " . $err);
      $res->error->_value = "service_unavailable";
    } else {
      $tab_col["v"] = array("bib_nr", "navn", "tlf_nr", "fax_nr", "email", "badr", "bpostnr", "bcity", "type", "*");
      $tab_col["vv"] = array("bib_nr", "navn", "tlf_nr", "fax_nr", "email", "badr", "bpostnr", "bcity", "bib_type", "*");
      $tab_col["vb"] = array("bib_nr", "*");
      $tab_col["vbst"] = array("bib_nr", "*");
      $tab_col["vd"] = array("bib_nr", "svar_fax", "*");
      $tab_col["vk"] = array("bib_nr", "*");
      $tab_col["oao"] = array("bib_nr", "*");
      foreach ($tab_col as $prefix => $arr)
        foreach ($arr as $col)
          $q .= (empty($q) ? "" : ", ") .
                $prefix . '.' . $col .
                ($col == "*" ? "" : ' "' . strtoupper($prefix . '.' . $col) . '"');
      $agency = $this->strip_agency($param->agencyId->_value);
      $oci->bind("bind_bib_nr", $agency);
      $oci->set_query("SELECT " . $q . "
                       FROM vip v, vip_vsn vv, vip_beh vb, vip_bestil vbst, vip_danbib vd, vip_kat vk, open_agency_ors oao
                       WHERE v.bib_nr = vd.bib_nr (+)
                         AND v.bib_vsn = vv.bib_nr (+)
                         AND v.bib_nr = vk.bib_nr
                         AND v.bib_nr = vb.bib_nr (+)
                         AND v.bib_nr = vbst.bib_nr (+)
                         AND v.bib_nr = oao.bib_nr (+)
                         AND v.bib_nr = :bind_bib_nr");
      $oa_row = $oci->fetch_into_assoc();
      $this->sanitize_array($oa_row);
      switch ($param->service->_value) {
        case "information":
          $inf = &$res->information->_value;
          $inf->agencyId->_value = $oa_row["VV.BIB_NR"];
          $inf->agencyName->_value = $oa_row["VV.NAVN"];
          $inf->agencyPhone->_value = $oa_row["VV.TLF_NR"];
          $inf->agencyFax->_value = $oa_row["VV.FAX_NR"];
          $inf->agencyEmail->_value = $oa_row["VV.EMAIL"];
          $inf->agencyType->_value = $oa_row["VV.BIB_TYPE"];
          $inf->branchId->_value = $oa_row["V.BIB_NR"];
          $inf->branchName->_value = $oa_row["V.NAVN"];
          $inf->branchPhone->_value = $oa_row["V.TLF_NR"];
          $inf->branchFax->_value = $oa_row["VD.SVAR_FAX"];
          $inf->branchEmail->_value = $oa_row["V.EMAIL"];
          $inf->branchType->_value = $oa_row["V.TYPE"];
          $inf->postalAddress->_value = $oa_row["V.BADR"];
          $inf->postalCode->_value = $oa_row["V.BPOSTNR"];
          $inf->city->_value = $oa_row["V.BCITY"];
          $inf->isil->_value = $oa_row["ISIL"];
          $inf->junction->_value = $oa_row["KNUDEPUNKT"];
          $inf->kvik->_value = ($oa_row["KVIK"] == "kvik" ? "YES" : "NO");
          $inf->lookupUrl->_value = $oa_row["URL_VIDERESTIL"];
          $inf->norfri->_value = ($oa_row["NORFRI"] == "norfri" ? "YES" : "NO");
          $inf->requestOrder->_value = $oa_row["USE_LAANEVEJ"];
          if (is_null($inf->sender->_value = $oa_row["CHANGE_REQUESTER"]))
            $inf->sender->_value = $oa_row["V.BIB_NR"];
          //var_dump($res->information->_value); die();
          break;
        case "orsAnswer":
          $orsA = &$res->orsAnswer->_value;
          $orsA->responder->_value = $oa_row["OAO.BIB_NR"];
          $orsA->willReceive->_value = (in_array($oa_row["ANSWER"], array("z3950", "mail", "ors")) ? "YES" : "");
          $orsA->protocol->_value = $oa_row["ANSWER"];
          $orsA->userId->_value = $oa_row["ANSWER_Z3950_USER"];
          $orsA->groupId->_value = $oa_row["ANSWER_Z3950_GROUP"];
          $orsA->passWord->_value = ($oa_row["ANSWER"] == "z3950" ? $oa_row["ANSWER_Z3950_PASSWORD"] : $oa_row["ANSWER_NCIP_AUTH"]);
          if ($oa_row["ANSWER"] == "z3950")
            $orsA->address->_value = $oa_row["ANSWER_Z3950_ADDRESS"];
          elseif ($oa_row["ANSWER"] == "mail")
            $orsA->address->_value = $oa_row["ANSWER_MAIL_ADDRESS"];
          //var_dump($res->orsAnswer->_value); die();
          break;
        case "orsCancelRequestUser":
          $orsCRU = &$res->orsCancelRequestUser->_value;
          $orsCRU->responder->_value = $oa_row["VK.BIB_NR"];
          $orsCRU->willReceive->_value = ($oa_row["NCIP_CANCEL"] == "J" ? "YES" : "NO");
          $orsCRU->address->_value = $oa_row["NCIP_CANCEL_ADDRESS"];
          $orsCRU->passWord->_value = $oa_row["NCIP_CANCEL_PASSWORD"];
          //var_dump($res->orsCancelRequestUser->_value); die();
          break;
        case "orsEndUserRequest":
          $orsER = &$res->orsEndUserRequest->_value;
          $orsER->responder->_value = $oa_row["VB.BIB_NR"];
          $orsER->willReceive->_value = ($oa_row["BEST_MODT"] == "J" ? "YES" : "NO");
          switch ($oa_row["BESTIL_VIA"]) {
            case "A": 
            case "B": 
              $orsER->protocol->_value = "mail"; 
              $orsER->address->value = $oa_row["EMAIL_BESTIL"];
              $orsER->format->_value = ($oa_row["BESTIL_VIA"] == "A" ? "text" : "ill0");
              break;
            case "C": 
              $orsER->protocol->_value = "ors"; 
              break;
            case "D": 
              $orsER->protocol->_value = "ncip"; 
              $orsER->address->value = $oa_row["NCIP_ADDRESS"];
              $orsER->passWord->_value = $oa_row["NCIP_PASSWORD"];
              break;
          }
          //var_dump($res->orsEndUserRequest->_value); die();
          break;
        case "orsItemRequest":
          $orsIR = &$res->orsItemRequest->_value;
          $orsIR->responder->_value = $oa_row["VD.BIB_NR"];
          switch ($oa_row["MAILBESTIL_VIA"]) {
            case "A": $orsIR->willReceive->_value = "YES";
                      $orsIR->protocol->_value = "mail"; 
                      $orsIR->address->_value = $oa_row["BEST_EMAIL"];
                      break;
            case "B": $orsIR->willReceive->_value = "YES";
                      $orsIR->protocol->_value = "ors"; 
                      break;
            case "C": $orsIR->willReceive->_value = "YES";
                      $orsIR->protocol->_value = "z3950"; 
                      $orsIR->address->_value = $oa_row["URL_ITEMORDER_BESTIL"];
                      break;
            case "D": $orsIR->willReceive->_value = "NO"; 
                      break;
            default:  $orsIR->willReceive->_value = "NO"; 
                      break;
          }
          $orsIR->userId->_value = $oa_row["ZBESTIL_USERID"];
          $orsIR->groupId->_value = $oa_row["ZBESTIL_GROUPID"];
          $orsIR->passWord->_value = $oa_row["ZBESTIL_PASSW"];
          if ($oa_row["MAILBESTIL_VIA"] == "A")
            switch ($oa_row["FORMAT_BEST"]) {
              case "illdanbest": $orsIR->format->_value = "text"; break;
              case "ill0form": $orsIR->format->_value = "ill0"; break;
              case "ill5form": $orsIR->format->_value = "ill0"; break;
            }
          //var_dump($res->orsItemRequest->_value); die();
          break;
        case "orsLookupUser":
          $orsLU = &$res->orsLookupUser->_value;
          $orsLU->responder->_value = $oa_row["VK.BIB_NR"];
          $orsLU->willReceive->_value = ($oa_row["NCIP_LOOKUP_USER"] == "J" ? "YES" : "NO");
          $orsLU->address->_value = $oa_row["NCIP_LOOKUP_USER_ADDRESS"];
          $orsLU->passWord->_value = $oa_row["NCIP_LOOKUP_USER_PASSWORD"];
          //var_dump($res->orsLookupUser->_value); die();
          break;
        case "orsReceipt":
          $orsR = &$res->orsReceipt->_value;
          $orsR->responder->_value = $oa_row["VD.BIB_NR"];
          $orsR->willReceive->_value = (in_array($oa_row["MAILKVITTER_VIA"], array("A", "B")) ? "YES" : "NO");
          $orsR->protocol->_value = (in_array($oa_row["MAILKVITTER_VIA"], array("A", "B")) ? "mail" : "");
          $orsR->address->_value = $oa_row["KVIT_EMAIL"];
          if ($oa_row["FORMAT_KVIT"] == "ill0form") $orsR->format->_value = "ill0";
          elseif ($oa_row["FORMAT_KVIT"] == "ill5form") $orsR->format->_value = "ill0";
          elseif ($oa_row["FORMAT_KVIT"] == "illdanbest") $orsR->format->_value = "text";
          //var_dump($res->orsReceipt->_value); die();
          break;
        case "orsRenewItemUser":
          $orsRIU = &$res->orsRenewItemUser->_value;
          $orsRIU->responder->_value = $oa_row["VK.BIB_NR"];
          $orsRIU->willReceive->_value = ($oa_row["NCIP_RENEW"] == "J" ? "YES" : "NO");
          $orsRIU->address->_value = $oa_row["NCIP_RENEW_ADDRESS"];
          $orsRIU->passWord->_value = $oa_row["NCIP_RENEW_PASSWORD"];
          //var_dump($res->orsRenewItemUser->_value); die();
          break;
        case "orsShipping":
          $orsS = &$res->orsShipping->_value;
          $orsS->responder->_value = $oa_row["OAO.BIB_NR"];
          $orsS->willReceive->_value = (in_array($oa_row["SHIPPING"], array("z3950", "mail", "ors")) ? "YES" : "");
          $orsS->protocol->_value = $oa_row["SHIPPING"];
          $orsS->address->_value = "";
          $orsS->userId->_value = $oa_row["SHIPPING_Z3950_USER"];
          $orsS->groupId->_value = $oa_row["SHIPPING_Z3950_GROUP"];
          $orsS->passWord->_value = ($oa_row["SHIPPING"] == "z3950" ? $oa_row["SHIPPING_Z3950_PASSWORD"] : $oa_row["SHIPPING_NCIP_AUTH"]);
            if ($oa_row["SHIPPING"] == "z3950")
              $orsS->address->_value = $oa_row["SHIPPING_Z3950_ADDRESS"];
          //var_dump($res->orsShipping->_value); die();
          break;
        case "serverInformation":
          $serI = &$res->serverInformation->_value;
          $serI->responder->_value = $oa_row["VD.BIB_NR"];
          $serI->isil->_value = $oa_row["ISIL"];
          $serI->address->_value = $oa_row["URL_ITEMORDER_BESTIL"];
          $serI->userId->_value = $oa_row["ZBESTIL_USERID"];
          $serI->groupId->_value = $oa_row["ZBESTIL_GROUPID"];
          $serI->passWord->_value = $oa_row["ZBESTIL_PASSW"];
          //var_dump($res->serverInformation->_value); die();
          break;
        default:
          $res->error->_value = "error_in_request";
      }
    }


    //var_dump($res); var_dump($param); die();
    $ret->serviceResponse->_value = $res;
    return $ret;
  }

 /** \brief
  *
  */
  public function nameList($param) {
    $oci = new Oci($this->config->get_value("agency_credentials","setup"));
    $oci->set_charset("UTF8");
    $oci->connect();
    if ($err = $oci->get_error_string()) {
      verbose::log(FATAL, "OpenAgency:: OCI connect error: " . $err);
      $res->error->_value = "service_unavailable";
    } else {
      if ($param->libraryType->_value == "Folkebibliotek" ||
          $param->libraryType->_value == "Forskningsbibliotek") {
        $oci->bind("bind_bib_type", $param->libraryType->_value);
        $add_bib_type = " WHERE bib_type = :bind_bib_type";
        $oci->set_query("SELECT bib_nr, navn FROM vip_vsn" . $add_bib_type);
        while ($vv_row = $oci->fetch_into_assoc()) {
          $o->agencyId->_value = $vv_row["BIB_NR"];;
          $o->agencyName->_value = $vv_row["NAVN"];
          $res->agency[]->_value = $o;
          unset($o);
        }
      } else
        $res->error->_value = "error_in_request";
    }
    //var_dump($res); var_dump($param); die();
    $ret->nameListResponse->_value = $res;
    return $ret;
  }

 /** \brief
  *
  */
  public function proxyDomains($param) {  // ????
    $oci = new Oci($this->config->get_value("agency_credentials","setup"));
    $oci->set_charset("UTF8");
    $oci->connect();
    if ($err = $oci->get_error_string()) {
      verbose::log(FATAL, "OpenAgency:: OCI connect error: " . $err);
      $res->error->_value = "service_unavailable";
    } else {
/* 2 come ...
      //$oci->set_query("SELECT bib_nr, navn FROM vip_vsn");
      while ($vv_row = $oci->fetch_into_assoc()) {
        $o->domain->_value = 
        $o->ip->_value = 
        $o->userId->_value = 
        $o->passWord->_value = 
        $res->domains[]->_value = $o;
        unset($o);
      }
*/
    }
    //var_dump($res); var_dump($param); die();
    $ret->proxyDomainsResponse->_value = $res;
    return $ret;
  }

 /** \brief
  *
  */
  public function proxyIp($param) {  // ????
    $oci = new Oci($this->config->get_value("agency_credentials","setup"));
    $oci->set_charset("UTF8");
    $oci->connect();
    if ($err = $oci->get_error_string()) {
      verbose::log(FATAL, "OpenAgency:: OCI connect error: " . $err);
      $res->error->_value = "service_unavailable";
    } else {
      //$oci->set_query("SELECT bib_nr, navn FROM vip_vsn");
      $res = "";
    }

    //var_dump($res); var_dump($param); die();
    $ret->proxyIpResponse->_value = $res;
    return $ret;
  }

 /** \brief
  *  return only digits, so something like DK-710100 returns 710100
  */
  private function strip_agency($id) {
    return preg_replace('/\D/', '', $id);
  }

 /** \brief
  *  removes chr-10 and chr-13
  */
  private function sanitize_array(&$arr) {
    foreach ($arr as $key => $val)
      if (is_scalar($val))
        $arr[$key] = str_replace("\r", " ", str_replace("\n", " ", $val));
  }

}

/**
 *   MAIN 
 */

$ws=new openAgency('openagency.ini');
$ws->handle_request();

?>
