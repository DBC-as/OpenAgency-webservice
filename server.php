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


require_once('OLS_class_lib/webServiceServer_class.php');
require_once('OLS_class_lib/oci_class.php');
require_once 'OLS_class_lib/memcache_class.php';

class openAgency extends webServiceServer {
  protected $cache;

  public function __construct() {
    webServiceServer::__construct('openagency.ini');
    $this->cache = new cache($this->config->get_value('cache_host', 'setup'),
                             $this->config->get_value('cache_port', 'setup'),
                             $this->config->get_value('cache_expire', 'setup'));

  }


  /** \brief
   *
   * Request:
   * - agencyId
   * - AutService: autPotential, autRequester or autProvider
   * - materialType
   * Response:
   * - autPotential
   * or
   * - autProvider
   * or
   * - autRequester
   * or
   * - error
   **/
  function automation($param) {
    if (!$this->aaa->has_right('openagency', 500))
      $res->error->_value = 'authentication_error';
    else {
      $agency = $this->strip_agency($param->agencyId->_value);
      $cache_key = 'OA_aut_' . $this->version . $agency . $param->autService->_value . $param->materialType->_value;
      if ($ret = $this->cache->get($cache_key)) {
        verbose::log(STAT, 'Cache hit');
        return $ret;
      }
      $oci = new Oci($this->config->get_value('agency_credentials','setup'));
      $oci->set_charset('UTF8');
      try {
        $oci->connect();
      }
      catch (ociException $e) {
        verbose::log(FATAL, 'OpenAgency('.__LINE__.'):: OCI connect error: ' . $oci->get_error_string());
        $res->error->_value = 'service_unavailable';
      }
      if (empty($res->error)) {
        switch ($param->autService->_value) {
          case 'autPotential':
            try {
              $oci->bind('bind_laantager', $agency);
              $oci->bind('bind_materiale_id', $param->materialType->_value);
              $oci->set_query('SELECT id_nr, valg
                              FROM vip_fjernlaan
                              WHERE laantager = :bind_laantager
                              AND materiale_id = :bind_materiale_id');
              $vf_row = $oci->fetch_into_assoc();
            }
            catch (ociException $e) {
              verbose::log(FATAL, 'OpenAgency('.__LINE__.'):: OCI select error: ' . $oci->get_error_string());
              $res->error->_value = 'service_unavailable';
            }
            if (empty($res->error)) {
              if ($vf_row['VALG'] == 'a') {
                try {
                  $oci->bind('bind_materiale_id', $param->materialType->_value);
                  $J = 'J';
                  $oci->bind('bind_status', $J);
                  $oci->set_query('SELECT laangiver
                                  FROM vip_fjernlaan
                                  WHERE materiale_id = :bind_materiale_id
                                  AND status = :bind_status');    // ??? NULL og DISTINCT
                  $ap = &$res->autPotential->_value;
                  $ap->materialType->_value = $param->materialType->_value;
                  while ($vf_row = $oci->fetch_into_assoc())
                    if ($vf_row['LAANGIVER'])
                      $ap->responder[]->_value = $vf_row['LAANGIVER'];
                }
                catch (ociException $e) {
                  verbose::log(FATAL, 'OpenAgency('.__LINE__.'):: OCI select error: ' . $oci->get_error_string());
                  $res->error->_value = 'service_unavailable';
                }
              }
            }
            elseif ($vf_row['VALG'] == 'l') {
              try {
                $oci->bind('bind_fjernlaan_id', $vf_row['ID_NR']);
                $oci->set_query('SELECT bib_nr
                                FROM vip_fjernlaan_bibliotek
                                WHERE fjernlaan_id = :bind_fjernlaan_id');
                $ap = &$res->autPotential->_value;
                $ap->materialType->_value = $param->materialType->_value;
                while ($vfb_row = $oci->fetch_into_assoc())
                  $ap->responder[]->_value = $this->normalize_agency($vfb_row['BIB_NR']);
              }
              catch (ociException $e) {
                verbose::log(FATAL, 'OpenAgency('.__LINE__.'):: OCI select error: ' . $oci->get_error_string());
                $res->error->_value = 'service_unavailable';
              }
            }
            else
              $res->error->_value = 'no_agencies_found';
            break;
          case 'autRequester':
            try {
              $oci->bind('bind_laantager', $agency);
              $oci->bind('bind_materiale_id', $param->materialType->_value);
              $oci->set_query('SELECT *
                              FROM vip_fjernlaan
                              WHERE laantager = :bind_laantager
                              AND materiale_id = :bind_materiale_id');
              $ar = &$res->autRequester->_value;
              $ar->requester->_value = $agency;
              $ar->materialType->_value = $param->materialType->_value;
              if ($vf_row = $oci->fetch_into_assoc()) {
                if ($vf_row['STATUS'] == 'T') {
                  $ar->willSend->_value = 'TEST';
                }
                elseif ($vf_row['STATUS'] == 'J') {
                  $ar->willSend->_value = 'YES';
                }
                else {
                  $ar->willSend->_value = 'NO';
                }
                $ar->autPeriod->_value = $vf_row['PERIODE'];
                $ar->autId->_value = $vf_row['ID_NR'];
                $ar->autChoice->_value = $vf_row['VALG'];
                $ar->autRes->_value = ($vf_row['RESERVERING'] == 'J' ? 'YES' : 'NO');
              }
              else
                $ar->willSend->_value = 'NO';
            }
            catch (ociException $e) {
              verbose::log(FATAL, 'OpenAgency('.__LINE__.'):: OCI select error: ' . $oci->get_error_string());
              $res->error->_value = 'service_unavailable';
            }
            break;
          case 'autProvider':
            try {
              $oci->bind('bind_laangiver', $agency);
              $oci->bind('bind_materiale_id', $param->materialType->_value);
              $oci->set_query('SELECT *
                              FROM vip_fjernlaan
                              WHERE laangiver = :bind_laangiver
                              AND materiale_id = :bind_materiale_id');
              $ap = &$res->autProvider->_value;
              $ap->provider->_value = $agency;
              $ap->materialType->_value = $param->materialType->_value;
              if ($vf_row = $oci->fetch_into_assoc()) {
                $ap->willReceive->_value = ($vf_row['STATUS'] == 'J' ? 'YES' : 'NO');
                $ap->autPeriod->_value = $vf_row['PERIODE'];
                $ap->autId->_value = $vf_row['ID_NR'];
              }
              else
                $ap->willReceive->_value = 'NO';
            }
            catch (ociException $e) {
              verbose::log(FATAL, 'OpenAgency('.__LINE__.'):: OCI select error: ' . $oci->get_error_string());
              $res->error->_value = 'service_unavailable';
            }
            break;
          default:
            $res->error->_value = 'error_in_request';
        }
      }
    }
    //var_dump($res); var_dump($param); die();
    $ret->automationResponse->_value = $res;
    $ret = $this->objconvert->set_obj_namespace($ret, $this->xmlns['oa']);
    if (empty($res->error)) $this->cache->set($cache_key, $ret);
    return $ret;
  }

  /** \brief
   *
   * Request:
   * - email
   * Response:
   * - encryption
   * - - encrypt
   * - - email
   * - - agencyId
   * - - key
   * - - base64
   * - - date
   * or
   * - error
   */
  public function encryption($param) {
    if (!$this->aaa->has_right('openagency', 500))
      $res->error->_value = 'authentication_error';
    else {
      $cache_key = 'OA_enc_' . $this->version . $param->email->_value;
      if ($ret = $this->cache->get($cache_key)) {
        verbose::log(STAT, 'Cache hit');
        return $ret;
      }
      $oci = new Oci($this->config->get_value('agency_credentials','setup'));
      $oci->set_charset('UTF8');
      try {
        $oci->connect();
      }
      catch (ociException $e) {
        verbose::log(FATAL, 'OpenAgency('.__LINE__.'):: OCI connect error: ' . $oci->get_error_string());
        $res->error->_value = 'service_unavailable';
      }
      if (empty($res->error)) {
        try {
          $oci->bind('bind_email', $param->email->_value);
          $oci->set_query('SELECT * FROM vip_krypt WHERE email = :bind_email');
          while ($vk_row = $oci->fetch_into_assoc()) {
            $o->encrypt->_value = 'YES';
            $o->email->_value = $param->email->_value;
            $o->agencyId->_value = $vk_row['BIBLIOTEK'];;
            $o->key->_value = $vk_row['KEY'];
            $o->base64->_value = ($vk_row['NOTBASE64'] == 'ja' ? 'NO' : 'YES');
            $o->date->_value = $vk_row['UDL_DATO'];
            $res->encryption[]->_value = $o;
            unset($o);
          }
          if (empty($res))
            $res->encryption[]->_value->encrypt->_value = 'NO';
        }
        catch (ociException $e) {
          verbose::log(FATAL, 'OpenAgency('.__LINE__.'):: OCI select error: ' . $oci->get_error_string());
          $res->error->_value = 'service_unavailable';
        }
      }
    }

    //var_dump($res); var_dump($param); die();
    $ret->encryptionResponse->_value = $res;
    $ret = $this->objconvert->set_obj_namespace($ret, $this->xmlns['oa']);
    if (empty($res->error)) $this->cache->set($cache_key, $ret);
    return $ret;
  }
  /** \brief
   *
   */
  public function endUserOrderPolicy($param) {
    if (!$this->aaa->has_right('openagency', 500))
      $res->error->_value = 'authentication_error';
    else {
      $agency = $this->strip_agency($param->agencyId->_value);
      $mat_type = strtolower($param->orderMaterialType->_value);
      $cache_key = 'OA_endUOP_' . $this->version . $agency . $param->orderMaterialType->_value . $param->ownedByAgency->_value;
      if ($ret = $this->cache->get($cache_key)) {
        verbose::log(STAT, 'Cache hit');
        return $ret;
      }
      $oci = new Oci($this->config->get_value('agency_credentials','setup'));
      $oci->set_charset('UTF8');
      try {
        $oci->connect();
      }
      catch (ociException $e) {
        verbose::log(FATAL, 'OpenAgency('.__LINE__.'):: OCI connect error: ' . $oci->get_error_string());
        $res->error->_value = 'service_unavailable';
      }
      if (empty($res->error)) {
        $assoc['cdrom']     = array('CDROM_BEST_MODT', 'BEST_TEKST_CDROM');
        $assoc['journal']   = array('PER_BEST_MODT',   'BEST_TEKST_PER');
        $assoc['monograph'] = array('MONO_BEST_MODT',  'BEST_TEKST');
        $assoc['music']     = array('MUSIK_BEST_MODT', 'BEST_TEKST_MUSIK');
        $assoc['newspaper'] = array('AVIS_BEST_MODT',  'BEST_TEKST_AVIS');
        $assoc['video']     = array('VIDEO_BEST_MODT', 'BEST_TEKST_VIDEO');
        if (strtolower($param->ownedByAgency->_value) == 'true' || $param->ownedByAgency->_value == '1') {
          $fjernl = '';
        }
        elseif (strtolower($param->ownedByAgency->_value) == 'false' || $param->ownedByAgency->_value == '0') {
          $fjernl = '_FJL';
        }
        if (isset($fjernl) && $assoc[$mat_type]) {
          $will_receive = $assoc[$mat_type][0] . $fjernl;
          try {
            $oci->bind('bind_bib_nr', $agency);
            $oci->set_query('SELECT best_modt, ' . $will_receive . ' "WR", vt.*, vte.*
                            FROM vip_beh vb, vip_txt vt, vip_txt_eng vte
                            WHERE vb.bib_nr = :bind_bib_nr
                            AND vb.bib_nr = vt.bib_nr (+)
                            AND vb.bib_nr = vte.bib_nr (+)');
            if ($vb_row = $oci->fetch_into_assoc()) {
              $res->willReceive->_value =
                ($vb_row['BEST_MODT'] == 'J' && ($vb_row['WR'] == 'J' || $vb_row['WR'] == 'B') ? 'true' : 'false');
              if ($vb_row['WR'] == 'B') {
                $col = $assoc[$mat_type][1] . $fjernl;
                $cond_d->_attributes->language->_value = 'dan';
                $cond_d->_value = $vb_row[$col];
                $res->condition[] = $cond_d;
                $cond_e->_attributes->language->_value = 'eng';
                $cond_e->_value = $vb_row[$col.'_E'];
                $res->condition[] = $cond_e;
              }
            }
          }
          catch (ociException $e) {
            verbose::log(FATAL, 'OpenAgency('.__LINE__.'):: OCI select error: ' . $oci->get_error_string());
            $res->error->_value = 'service_unavailable';
          }
        }
        else
          $res->error->_value = 'error_in_request';
      }
      if (empty($res))
        $res->error->_value = 'no_agencies_found';
    }

    //var_dump($res); var_dump($param); die();
    $ret->endUserOrderPolicyResponse->_value = $res;
    $ret = $this->objconvert->set_obj_namespace($ret, $this->xmlns['oa']);
    if (empty($res->error)) $this->cache->set($cache_key, $ret);
    return $ret;
  }

  /** \brief
   *
   */
  public function service($param) {
    if (!$this->aaa->has_right('openagency', 500))
      $res->error->_value = 'authentication_error';
    else {
      $agency = $this->strip_agency($param->agencyId->_value);
      $cache_key = 'OA_ser_' . $this->version . $agency . $param->service->_value;
      if ($ret = $this->cache->get($cache_key)) {
        verbose::log(STAT, 'Cache hit');
        return $ret;
      }
      $oci = new Oci($this->config->get_value('agency_credentials','setup'));
      $oci->set_charset('UTF8');
      try {
        $oci->connect();
      }
      catch (ociException $e) {
        verbose::log(FATAL, 'OpenAgency('.__LINE__.'):: OCI connect error: ' . $oci->get_error_string());
        $res->error->_value = 'service_unavailable';
      }
      if (empty($res->error)) {
        $tab_col['v'] = array('bib_nr', 'navn', 'tlf_nr', 'fax_nr', 'email', 'badr', 'bpostnr', 'bcity', 'type', '*');
        $tab_col['vv'] = array('bib_nr', 'navn', 'tlf_nr', 'fax_nr', 'email', 'badr', 'bpostnr', 'bcity', 'bib_type', '*');
        $tab_col['vb'] = array('bib_nr', '*');
        $tab_col['vbst'] = array('bib_nr', 'ncip_address', '*');
        $tab_col['vd'] = array('bib_nr', 'svar_fax', 'svar_email', '*');
        $tab_col['vk'] = array('bib_nr', '*');
        $tab_col['oao'] = array('bib_nr', '*');
        foreach ($tab_col as $prefix => $arr) {
          foreach ($arr as $col) {
            $q .= (empty($q) ? '' : ', ') .
                  $prefix . '.' . $col .
                  ($col == '*' ? '' : ' "' . strtoupper($prefix . '.' . $col) . '"');
          }
        }
        try {
          $oci->bind('bind_bib_nr', $agency);
          $oci->set_query('SELECT ' . $q . '
                          FROM vip v, vip_vsn vv, vip_beh vb, vip_bestil vbst, vip_danbib vd, vip_kat vk, open_agency_ors oao
                          WHERE v.bib_nr = vd.bib_nr (+)
                          AND v.kmd_nr = vv.bib_nr (+)
                          AND v.bib_nr = vk.bib_nr (+)
                          AND v.bib_nr = vb.bib_nr (+)
                          AND v.bib_nr = vbst.bib_nr (+)
                          AND v.bib_nr = oao.bib_nr (+)
                          AND v.bib_nr = :bind_bib_nr');
          $oa_row = $oci->fetch_into_assoc();
          $this->sanitize_array($oa_row);
        }
        catch (ociException $e) {
          verbose::log(FATAL, 'OpenAgency('.__LINE__.'):: OCI select error: ' . $oci->get_error_string());
          $res->error->_value = 'service_unavailable';
        }
        if (empty($oa_row))
          $res->error->_value = 'agency_not_found';
        if (empty($res->error)) {
//        verbose::log(TRACE, 'OpenAgency('.__LINE__.'):: action=service&agencyId=' . $param->agencyId->_value .  '&service=' . $param->service->_value);
          switch ($param->service->_value) {
            case 'information':
              $inf = &$res->information->_value;
              $inf->agencyId->_value = $this->normalize_agency($oa_row['VV.BIB_NR']);
              $inf->agencyName->_value = $oa_row['VV.NAVN'];
              $inf->agencyPhone->_value = $oa_row['VV.TLF_NR'];
              $inf->agencyFax->_value = $oa_row['VV.FAX_NR'];
              $inf->agencyEmail->_value = $oa_row['VV.EMAIL'];
              $inf->agencyType->_value = $oa_row['VV.BIB_TYPE'];
              $inf->agencyCatalogueUrl->_value = $oa_row['URL_BIB_KAT'];
              $inf->branchId->_value = $this->normalize_agency($oa_row['V.BIB_NR']);
              $inf->branchName->_value = $oa_row['V.NAVN'];
              $inf->branchPhone->_value = $oa_row['V.TLF_NR'];
              $inf->branchFax->_value = $oa_row['VD.SVAR_FAX'];
              $inf->branchEmail->_value = $oa_row['V.EMAIL'];
              $inf->branchType->_value = $oa_row['V.TYPE'];
              if ($oa_row['AFSAETNINGSBIBLIOTEK'])
                $inf->dropOffAgency->_value = $oa_row['AFSAETNINGSBIBLIOTEK'];
              $inf->postalAddress->_value = $oa_row['V.BADR'];
              $inf->postalCode->_value = $oa_row['V.BPOSTNR'];
              $inf->city->_value = $oa_row['V.BCITY'];
              $inf->isil->_value = $this->normalize_agency($oa_row['ISIL']);
              $inf->junction->_value = $oa_row['KNUDEPUNKT'];
              $inf->kvik->_value = ($oa_row['KVIK'] == 'kvik' ? 'YES' : 'NO');
              $inf->lookupUrl->_value = $oa_row['URL_VIDERESTIL'];
              $inf->norfri->_value = ($oa_row['NORFRI'] == 'norfri' ? 'YES' : 'NO');
              $inf->requestOrder->_value = $oa_row['USE_LAANEVEJ'];
              if (is_null($inf->sender->_value = $this->normalize_agency($oa_row['CHANGE_REQUESTER'])))
                $inf->sender->_value = $this->normalize_agency($oa_row['V.BIB_NR']);
              $inf->replyToEmail->_value = $oa_row['VD.SVAR_EMAIL'];
              //print_r($oa_row); var_dump($res->information->_value); die();
              break;
            case 'orsAnswer':
              $orsA = &$res->orsAnswer->_value;
              $orsA->responder->_value = $this->normalize_agency($oa_row['OAO.BIB_NR']);
              $orsA->willReceive->_value = (in_array($oa_row['ANSWER'], array('z3950', 'mail', 'ors')) ? 'YES' : '');
              $orsA->synchronous->_value = 'false';
              $orsA->protocol->_value = $oa_row['ANSWER'];
              if ($oa_row['ANSWER'] == 'z3950') {
                $orsA->address->_value = $oa_row['ANSWER_Z3950_ADDRESS'];
              }
              elseif ($oa_row['ANSWER'] == 'mail') {
                $orsA->address->_value = $oa_row['ANSWER_MAIL_ADDRESS'];
              }
              $orsA->userId->_value = $oa_row['ANSWER_Z3950_USER'];
              $orsA->groupId->_value = $oa_row['ANSWER_Z3950_GROUP'];
              $orsA->passWord->_value = ($oa_row['ANSWER'] == 'z3950' ? $oa_row['ANSWER_Z3950_PASSWORD'] : $oa_row['ANSWER_NCIP_AUTH']);
              //var_dump($res->orsAnswer->_value); die();
              break;
            case 'orsCancelRequestUser':
              $orsCRU = &$res->orsCancelRequestUser->_value;
              $orsCRU->responder->_value = $this->normalize_agency($oa_row['VK.BIB_NR']);
              $orsCRU->willReceive->_value = ($oa_row['NCIP_CANCEL'] == 'J' ? 'YES' : 'NO');
              $orsCRU->synchronous->_value = 'false';
              $orsCRU->address->_value = $oa_row['NCIP_CANCEL_ADDRESS'];
              $orsCRU->passWord->_value = $oa_row['NCIP_CANCEL_PASSWORD'];
              //var_dump($res->orsCancelRequestUser->_value); die();
              break;
            case 'orsEndUserRequest':
              $orsEUR = &$res->orsEndUserRequest->_value;
              $orsEUR->responder->_value = $this->normalize_agency($oa_row['VB.BIB_NR']);
              $orsEUR->willReceive->_value = ($oa_row['BEST_MODT'] == 'J' ? 'YES' : 'NO');
              $orsEUR->synchronous->_value = 'false';
              switch ($oa_row['BESTIL_VIA']) {
                case 'A':
                  $orsEUR->protocol->_value = 'mail';
                  $orsEUR->address->_value = $oa_row['EMAIL_BESTIL'];
                  $orsEUR->format->_value = 'text';
                  break;
                case 'B':
                  $orsEUR->protocol->_value = 'mail';
                  $orsEUR->address->_value = $oa_row['EMAIL_BESTIL'];
                  $orsEUR->format->_value = 'ill0';
                  break;
                case 'C':
                  $orsEUR->protocol->_value = 'ors';
                  break;
                case 'D':
                  $orsEUR->protocol->_value = 'ncip';
                  $orsEUR->address->_value = $oa_row['VBST.NCIP_ADDRESS'];
                  $orsEUR->passWord->_value = $oa_row['NCIP_PASSWORD'];
                  break;
              }
              //var_dump($res->orsEndUserRequest->_value); die();
              break;
            case 'orsEndUserIllRequest':
              $orsEUIR = &$res->orsEndUserIllRequest->_value;
              $orsEUIR->responder->_value = $this->normalize_agency($oa_row['VB.BIB_NR']);
              $orsEUIR->willReceive->_value = ($oa_row['BEST_MODT'] == 'J' ? 'YES' : 'NO');
              $orsEUIR->synchronous->_value = 'false';
              switch ($oa_row['BESTIL_FJL_VIA']) {
                case 'A':
                  $orsEUIR->protocol->_value = 'mail';
                  $orsEUIR->address->_value = $oa_row['EMAIL_FJL_BESTIL'];
                  $orsEUIR->format->_value = 'text';
                  break;
                case 'B':
                  $orsEUIR->protocol->_value = 'mail';
                  $orsEUIR->address->_value = $oa_row['EMAIL_FJL_BESTIL'];
                  $orsEUIR->format->_value = 'ill0';
                  break;
                case 'C':
                  $orsEUIR->protocol->_value = 'ors';
                  break;
              }
              break;
            case 'orsItemRequest':
              $orsIR = &$res->orsItemRequest->_value;
              $orsIR->responder->_value = $this->normalize_agency($oa_row['VD.BIB_NR']);
              switch ($oa_row['MAILBESTIL_VIA']) {
                case 'A':
                  $orsIR->willReceive->_value = 'YES';
                  $orsIR->synchronous->_value = 'false';
                  $orsIR->protocol->_value = 'mail';
                  $orsIR->address->_value = $oa_row['BEST_EMAIL'];
                  break;
                case 'B':
                  $orsIR->willReceive->_value = 'YES';
                  $orsIR->synchronous->_value = 'false';
                  $orsIR->protocol->_value = 'ors';
                  break;
                case 'C':
                  $orsIR->willReceive->_value = 'YES';
                  $orsIR->synchronous->_value = 'false';
                  $orsIR->protocol->_value = 'z3950';
                  $orsIR->address->_value = $oa_row['URL_ITEMORDER_BESTIL'];
                  break;
                case 'D':
                default:
                  $orsIR->willReceive->_value = 'NO';
                  if ($oa_row['BEST_TXT']) {
                    $help->_value = $oa_row['BEST_TXT'];
                    $help->_attributes->language->_value = 'dan';
                    $orsIR->reason = $help;
                  }
                  break;
              }
              if ($orsIR->willReceive->_value == 'YES') {
                if ($oa_row['ZBESTIL_USERID'])
                  $orsIR->userId->_value = $oa_row['ZBESTIL_USERID'];
                if ($oa_row['ZBESTIL_GROUPID'])
                  $orsIR->groupId->_value = $oa_row['ZBESTIL_GROUPID'];
                if ($oa_row['ZBESTIL_PASSW'])
                  $orsIR->passWord->_value = $oa_row['ZBESTIL_PASSW'];
                if ($oa_row['MAILBESTIL_VIA'] == 'A')
                  switch ($oa_row['FORMAT_BEST']) {
                    case 'illdanbest':
                      $orsIR->format->_value = 'text';
                      break;
                    case 'ill0form':
                      $orsIR->format->_value = 'ill0';
                      break;
                    case 'ill5form':
                      $orsIR->format->_value = 'ill0';
                      break;
                  }
              }
              //var_dump($res->orsItemRequest->_value); die();
              break;
            case 'orsLookupUser':
              $orsLU = &$res->orsLookupUser->_value;
              $orsLU->responder->_value = $this->normalize_agency($oa_row['VK.BIB_NR']);
              $orsLU->willReceive->_value = ($oa_row['NCIP_LOOKUP_USER'] == 'J' ? 'YES' : 'NO');
              $orsLU->synchronous->_value = 'false';
              $orsLU->address->_value = $oa_row['NCIP_LOOKUP_USER_ADDRESS'];
              $orsLU->passWord->_value = $oa_row['NCIP_LOOKUP_USER_PASSWORD'];
              //var_dump($res->orsLookupUser->_value); die();
              break;
            case 'orsRecall':
              $orsR = &$res->orsRecall->_value;
              $orsR->responder->_value = $this->normalize_agency($oa_row['OAO.BIB_NR']);
              $orsR->willReceive->_value = (in_array($oa_row['RECALL'], array('z3950', 'mail', 'ors')) ? 'YES' : '');
              $orsR->synchronous->_value = 'false';
              $orsR->protocol->_value = $oa_row['RECALL'];
              $orsR->address->_value = '';
              $orsR->userId->_value = $oa_row['RECALL_Z3950_USER'];
              $orsR->groupId->_value = $oa_row['RECALL_Z3950_GROUP'];
              $orsR->passWord->_value = ($oa_row['RECALL'] == 'z3950' ? $oa_row['RECALL_Z3950_PASSWORD'] : $oa_row['RECALL_NCIP_AUTH']);
              if ($oa_row['RECALL'] == 'z3950')
                $orsR->address->_value = $oa_row['RECALL_Z3950_ADDRESS'];
              //var_dump($res->orsRecall->_value); die();
              break;
            case 'orsReceipt':
              $orsR = &$res->orsReceipt->_value;
              $orsR->responder->_value = $this->normalize_agency($oa_row['VD.BIB_NR']);
              $orsR->willReceive->_value = (in_array($oa_row['MAILKVITTER_VIA'], array('A', 'B')) ? 'YES' : 'NO');
              $orsR->synchronous->_value = 'false';
              if ($oa_row['MAILKVITTER_VIA'] == 'A') {
                $orsR->protocol->_value = 'mail';
              }
              elseif ($oa_row['MAILKVITTER_VIA'] == 'B') {
                $orsR->protocol->_value = 'ors';
              }
              else {
                $orsR->protocol->_value = '';
              }
              $orsR->address->_value = $oa_row['KVIT_EMAIL'];
              if ($oa_row['FORMAT_KVIT'] == 'ill0form') {
                $orsR->format->_value = 'ill0';
              }
              elseif ($oa_row['FORMAT_KVIT'] == 'ill5form') {
                $orsR->format->_value = 'ill0';
              }
              elseif ($oa_row['FORMAT_KVIT'] == 'illdanbest') {
                $orsR->format->_value = 'text';
              }
              //var_dump($res->orsReceipt->_value); die();
              break;
            case 'orsRenew':
              $orsR = &$res->orsRenew->_value;
              $orsR->responder->_value = $this->normalize_agency($oa_row['OAO.BIB_NR']);
              if ($oa_row['RENEW'] == 'z3950' || $oa_row['RENEW'] == 'ors') {
                $orsR->willReceive->_value = 'YES';
                $orsR->synchronous->_value = 'false';
                $orsR->protocol->_value = $oa_row['RENEW'];
                if ($oa_row['RENEW'] == 'z3950') {
                  $orsR->address->_value = $oa_row['RENEW_Z3950_ADDRESS'];
                  $orsR->userId->_value = $oa_row['RENEW_Z3950_USER'];
                  $orsR->groupId->_value = $oa_row['RENEW_Z3950_GROUP'];
                  $orsR->passWord->_value = $oa_row['RENEW_Z3950_PASSWORD'];
                }
              }
              else {
                $orsR->willReceive->_value = 'NO';
                $orsR->synchronous->_value = 'false';
              }
              //var_dump($res->orsRenew->_value); die();
              break;
            case 'orsRenewAnswer':
              $orsRA = &$res->orsRenewAnswer->_value;
              $orsRA->responder->_value = $this->normalize_agency($oa_row['OAO.BIB_NR']);
              if ($oa_row['RENEWANSWER'] == 'z3950' || $oa_row['RENEWANSWER'] == 'ors') {
                $orsRA->willReceive->_value = 'YES';
                $orsRA->synchronous->_value = $oa_row['RENEW_ANSWER_SYNCHRONIC'] == 'J' ? 'true' : 'false';
                $orsRA->protocol->_value = $oa_row['RENEWANSWER'];
                if ($oa_row['RENEWANSWER'] == 'z3950') {
                  $orsRA->address->_value = $oa_row['RENEWANSWER_Z3950_ADDRESS'];
                  $orsRA->userId->_value = $oa_row['RENEWANSWER_Z3950_USER'];
                  $orsRA->groupId->_value = $oa_row['RENEWANSWER_Z3950_GROUP'];
                  $orsRA->passWord->_value = $oa_row['RENEWANSWER_Z3950_PASSWORD'];
                }
              }
              else {
                $orsRA->willReceive->_value = 'NO';
                $orsRA->synchronous->_value = 'false';
              }
              //var_dump($res->orsRenewAnswer->_value); die();
              break;
            case 'orsCancel':
              $orsC = &$res->orsCancel->_value;
              $orsC->responder->_value = $this->normalize_agency($oa_row['OAO.BIB_NR']);
              if ($oa_row['CANCEL'] == 'z3950' || $oa_row['CANCEL'] == 'ors') {
                $orsC->willReceive->_value = 'YES';
                $orsC->synchronous->_value = 'false';
                $orsC->protocol->_value = $oa_row['CANCEL'];
                if ($oa_row['CANCEL'] == 'z3950') {
                  $orsC->address->_value = $oa_row['CANCEL_Z3950_ADDRESS'];
                  $orsC->userId->_value = $oa_row['CANCEL_Z3950_USER'];
                  $orsC->groupId->_value = $oa_row['CANCEL_Z3950_GROUP'];
                  $orsC->passWord->_value = $oa_row['CANCEL_Z3950_PASSWORD'];
                }
              }
              else {
                $orsC->willReceive->_value = 'NO';
                $orsC->synchronous->_value = 'false';
              }
              //var_dump($res->orsCancel->_value); die();
              break;
            case 'orsCancelReply':
              $orsCR = &$res->orsCancelReply->_value;
              $orsCR->responder->_value = $this->normalize_agency($oa_row['OAO.BIB_NR']);
              if ($oa_row['CANCELREPLY'] == 'z3950' || $oa_row['CANCELREPLY'] == 'ors') {
                $orsCR->willReceive->_value = 'YES';
                $orsCR->synchronous->_value = $oa_row['CANCEL_ANSWER_SYNCHRONIC'] == 'J' ? 'true' : 'false';
                $orsCR->protocol->_value = $oa_row['CANCELREPLY'];
                if ($oa_row['CANCELREPLY'] == 'z3950') {
                  $orsCR->address->_value = $oa_row['CANCELREPLY_Z3950_ADDRESS'];
                  $orsCR->userId->_value = $oa_row['CANCELREPLY_Z3950_USER'];
                  $orsCR->groupId->_value = $oa_row['CANCELREPLY_Z3950_GROUP'];
                  $orsCR->passWord->_value = $oa_row['CANCELREPLY_Z3950_PASSWORD'];
                }
              }
              else {
                $orsCR->willReceive->_value = 'NO';
                $orsCR->synchronous->_value = 'false';
              }
              //var_dump($res->orsCancelReply->_value); die();
              break;
            case 'orsRenewItemUser':
              $orsRIU = &$res->orsRenewItemUser->_value;
              $orsRIU->responder->_value = $this->normalize_agency($oa_row['VK.BIB_NR']);
              $orsRIU->willReceive->_value = ($oa_row['NCIP_RENEW'] == 'J' ? 'YES' : 'NO');
              $orsRIU->synchronous->_value = 'false';
              $orsRIU->address->_value = $oa_row['NCIP_RENEW_ADDRESS'];
              $orsRIU->passWord->_value = $oa_row['NCIP_RENEW_PASSWORD'];
              //var_dump($res->orsRenewItemUser->_value); die();
              break;
            case 'orsShipping':
              $orsS = &$res->orsShipping->_value;
              $orsS->responder->_value = $this->normalize_agency($oa_row['OAO.BIB_NR']);
              $orsS->willReceive->_value = (in_array($oa_row['SHIPPING'], array('z3950', 'mail', 'ors')) ? 'YES' : '');
              $orsS->synchronous->_value = 'false';
              $orsS->protocol->_value = $oa_row['SHIPPING'];
              $orsS->address->_value = '';
              $orsS->userId->_value = $oa_row['SHIPPING_Z3950_USER'];
              $orsS->groupId->_value = $oa_row['SHIPPING_Z3950_GROUP'];
              $orsS->passWord->_value = ($oa_row['SHIPPING'] == 'z3950' ? $oa_row['SHIPPING_Z3950_PASSWORD'] : $oa_row['SHIPPING_NCIP_AUTH']);
              if ($oa_row['SHIPPING'] == 'z3950')
                $orsS->address->_value = $oa_row['SHIPPING_Z3950_ADDRESS'];
              //var_dump($res->orsShipping->_value); die();
              break;
            case 'serverInformation':
              $serI = &$res->serverInformation->_value;
              $serI->responder->_value = $this->normalize_agency($oa_row['VD.BIB_NR']);
              $serI->isil->_value = $this->normalize_agency($oa_row['ISIL']);
              $serI->address->_value = $oa_row['URL_ITEMORDER_BESTIL'];
              $serI->userId->_value = $oa_row['ZBESTIL_USERID'];
              $serI->groupId->_value = $oa_row['ZBESTIL_GROUPID'];
              $serI->passWord->_value = $oa_row['ZBESTIL_PASSW'];
              //var_dump($res->serverInformation->_value); die();
              break;
            case 'userParameters':
              $usrP = &$res->userParameters->_value;
              $get_obl = array('LD_CPR' => 'cpr',
                               'LD_ID' => 'common',
                               'LD_LKST' => 'barcode',
                               'LD_KLNR' => 'cardno',
                               'LD_TXT' => 'optional');
              $usrP->userIdType->_value = 'no_userid_selected';
              foreach ($get_obl as $key => $val) {
                if (substr($oa_row[$key],0,1) == 'O') {
                  $usrP->userIdType->_value = $val;
                  break;
                }
              }
              break;
            case 'userOrderParameters':
              $usrOP = &$res->userOrderParameters->_value;
              $u_fld = array('LD_CPR' => 'cpr', 
                             'LD_ID' => 'userId',
                             'LD_TXT' => 'customId',
                             'LD_LKST' => 'barcode',
                             'LD_KLNR' => 'cardno',
                             'LD_PIN' => 'pincode',
                             'LD_DATO' => 'userDateOfBirth',
                             'LD_NAVN' => 'userName',
                             'LD_ADR' => 'userAddress',
                             'LD_EMAIL' => 'userMail',
                             'LD_TLF' => 'userTelephone');
              foreach ($u_fld as $vip_key => $res_key) {
                $sw = $oa_row[$vip_key][0];
                if (in_array($sw, array('J', 'O'))) {
                  $f->userParameterType->_value = $res_key;
                  $f->parameterRequired->_value = ($sw == 'O'? '1' : '0');
                  $usrOP->userParameter[]->_value = $f;
                  unset($f);
                }
              }
              if (in_array($oa_row['LD_ID'][0], array('J', 'O'))) {
                if ($oa_row['LD_ID_TXT']) {
                  $f->_attributes->language->_value = 'dan';
                  $f->_value = $oa_row['LD_ID_TXT'];
                  $usrOP->userIdTxt[] = $f;
                  unset($f);
                }
                if ($oa_row['LD_ID_TXT_ENG']) {
                  $f->_attributes->language->_value = 'eng';
                  $f->_value = $oa_row['LD_ID_TXT_ENG'];
                  $usrOP->userIdTxt[] = $f;
                  unset($f);
                }
              }
              if (in_array($oa_row['LD_TXT'][0], array('J', 'O'))) {
                if ($oa_row['LD_TXT2']) {
                  $f->_attributes->language->_value = 'dan';
                  $f->_value = $oa_row['LD_TXT2'];
                  $usrOP->customIdTxt[] = $f;
                  unset($f);
                }
                if ($oa_row['LD_TXT2_ENG']) {
                  $f->_attributes->language->_value = 'eng';
                  $f->_value = $oa_row['LD_TXT2_ENG'];
                  $usrOP->customIdTxt[] = $f;
                  unset($f);
                }
              }
              $per = array('PER_NR' => 'volume',
                           'PER_HEFTE' => 'issue',
                           'PER_AAR' => 'publicationDateOfComponent',
                           'PER_SIDE' => 'pagination',
                           'PER_FORFATTER' => 'authorOfComponent',
                           'PER_TITEL' => 'titleOfComponent',
                           'PER_KILDE' => 'userReferenceSource');
              foreach ($per as $key => $val)
                $per_ill[$key . '_FJL'] = $val;
              $avis = array('AVIS_DATO' => 'issue',
                            'AVIS_AAR' => 'publicationDateOfComponent',
                            'AVIS_FORFATTER' => 'authorOfComponent',
                            'AVIS_TITEL' => 'titleOfComponent',
                            'AVIS_KILDE' => 'userReferenceSource');
              foreach ($avis as $key => $val)
                $avis_ill[$key . '_FJL'] = $val;
              $m_fld = array('CDROM_BEST_MODT' => array('cdrom', 'local'),
                             'CDROM_BEST_MODT_FJL' => array('cdrom', 'ill'),
                             'MONO_BEST_MODT' => array('monograph', 'local'),
                             'MONO_BEST_MODT_FJL' => array('monograph', 'ill'),
                             'MUSIK_BEST_MODT' => array('music', 'local'),
                             'MUSIK_BEST_MODT_FJL' => array('music', 'ill'),
                             'AVIS_BEST_MODT' => array('newspaper', 'local', $avis),
                             'AVIS_BEST_MODT_FJL' => array('newspaper', 'ill', $avis_ill),
                             'PER_BEST_MODT' => array('journal', 'local', $per),
                             'PER_BEST_MODT_FJL' => array('journal', 'ill', $per_ill),
                             'VIDEO_BEST_MODT' => array('video', 'local'),
                             'VIDEO_BEST_MODT_FJL' => array('video', 'ill'));
              foreach ($m_fld as $vip_key => $res_key) {
                if (in_array($oa_row[$vip_key], array('J', 'B'))) {
                  $f->orderMaterialType->_value = $res_key[0];
                  $f->orderType->_value = $res_key[1];
                  if (is_array($res_key[2])) {
                    foreach ($res_key[2] as $elem_vip_key => $elem_res_key) {
                      if (in_array($oa_row[$elem_vip_key], array('J', 'O'))) {
                        $p->itemParameterType->_value = $elem_res_key;
                        $p->parameterRequired->_value = ($oa_row[$elem_vip_key] == 'O'? '1' : '0');
                        $f->itemParameter[]->_value = $p;
                        unset($p);
                      }
                    }
                  }
                  $usrOP->orderParameters[]->_value = $f;
                  unset($f);
                }
              }
/*      <open:userOrderParameters>
            <!--1 or more repetitions:-->
            <open:userParameter>
               <open:userParameterType>?</open:userParameterType>
               <open:parameterRequired>?</open:parameterRequired>
            </open:userParameter>
            <!--Zero or more repetitions:-->
            <open:customIdTxt open:language="?">?</open:customIdTxt>
            <!--Zero or more repetitions:-->
            <open:userIdTxt open:language="?">?</open:userIdTxt>
            <!--1 or more repetitions:-->
            <open:orderParameters>
               <open:orderMaterialType>?</open:orderMaterialType>
               <open:orderType>?</open:orderType>
               <!--1 or more repetitions:-->
               <open:itemParameter>
                  <open:itemParameterType>?</open:itemParameterType>
                  <open:parameterRequired>?</open:parameterRequired>
               </open:itemParameter>
            </open:orderParameters>
         </open:userOrderParameters>                        */

    //print_r($oa_row); 
    //var_dump($res); var_dump($param); die();
              break;
            default:
              $res->error->_value = 'error_in_request';
          }
        }
      }
    }


    //var_dump($res); var_dump($param); die();
    $ret->serviceResponse->_value = $res;
    $ret = $this->objconvert->set_obj_namespace($ret, $this->xmlns['oa']);
    if (empty($res->error)) $this->cache->set($cache_key, $ret);
    return $ret;
  }

  /** \brief
   *
   */
  public function nameList($param) {
    if (!$this->aaa->has_right('openagency', 500))
      $res->error->_value = 'authentication_error';
    else {
      //var_dump($this->aaa->get_rights()); die();
      $cache_key = 'OA_namL_' . $this->version . $param->libraryType->_value;
      if ($ret = $this->cache->get($cache_key)) {
        verbose::log(STAT, 'Cache hit');
        return $ret;
      }
      $oci = new Oci($this->config->get_value('agency_credentials','setup'));
      $oci->set_charset('UTF8');
      try {
        $oci->connect();
      }
      catch (ociException $e) {
        verbose::log(FATAL, 'OpenAgency('.__LINE__.'):: OCI connect error: ' . $oci->get_error_string());
        $res->error->_value = 'service_unavailable';
      }
      if (empty($res->error)) {
        if ($param->libraryType->_value == 'Alle' ||
            $param->libraryType->_value == 'Folkebibliotek' ||
            $param->libraryType->_value == 'Forskningsbibliotek') {
          try {
            if ($param->libraryType->_value <> 'Alle') {
              $filter_bib_type = 'AND vsn.bib_type = :bind_bib_type';
              $oci->bind('bind_bib_type', $param->libraryType->_value);
            }
            $u = 'U';
            $oci->bind('bind_u', $u);
            $oci->set_query('SELECT vsn.bib_nr, vsn.navn
                            FROM vip v, vip_vsn vsn
                            WHERE v.bib_nr = vsn.bib_nr
                            AND (v.delete_mark is null or v.delete_mark = :bind_u)
                            ' . $filter_bib_type);
            while ($vv_row = $oci->fetch_into_assoc()) {
              $o->agencyId->_value = $this->normalize_agency($vv_row['BIB_NR']);
              $o->agencyName->_value = $vv_row['NAVN'];
              $res->agency[]->_value = $o;
              unset($o);
            }
          }
          catch (ociException $e) {
            verbose::log(FATAL, 'OpenAgency('.__LINE__.'):: OCI select error: ' . $oci->get_error_string());
            $res->error->_value = 'service_unavailable';
          }
        }
        else
          $res->error->_value = 'error_in_request';
      }
    }
    //var_dump($res); var_dump($param); die();
    $ret->nameListResponse->_value = $res;
    $ret = $this->objconvert->set_obj_namespace($ret, $this->xmlns['oa']);
    if (empty($res->error)) $this->cache->set($cache_key, $ret);
    return $ret;
  }

  /** \brief
   *
   * Request:
   * - agencyId
   * - agencyName
   * - postalCode
   * - city
   * - libraryType
   * - libraryStatus
   * - pickupAllowed

   * Response:
   * - library
   * - - agencyId
   * - - agencyName
   * - - agencyPhone
   * - - agencyEmail
   * - - postalAddress
   * - - postalCode
   * - - city
   * - - agencyWebsiteUrl *
   * - - pickupAgency
   * - - - branchId
   * - - - branchName
   * - - - branchPhone
   * - - - branchEmail
   * - - - postalAddress
   * - - - postalCode
   * - - - city
   * - - - isil
   * - - - branchWebsiteUrl *
   * - - - serviceDeclarationUrl *
   * - - - registrationFormUrl *
   * - - - paymentUrl *
   * - - - userStatusUrl *
   * - - - agencySubdivision
   * - - - openingHours
   * - - - temporarilyClosed
   * - - - temporarilyClosedReason
   * - - - pickupAllowed *
   * - or
   * - - error
   */
  public function pickupAgencyList($param) {
    if (!$this->aaa->has_right('openagency', 500))
      $res->error->_value = 'authentication_error';
    else {
      foreach (array('agencyId', 'agencyName', 'postalCode', 'city') as $par) {
        if (is_array($param->$par)) {
          foreach ($param->$par as $p) {
            if ($par == 'agencyId') {
              $ag = $this->strip_agency($p->_value);
              if ($ag)
                $ora_par[$par][] = $ag;
              $param_agencies[$ag] = $p->_value;
            }
            elseif ($p->_value) {
              $ora_par[$par][] = $p->_value;
            }
          }
        }
        elseif ($param->$par) {
          if ($par == 'agencyId') {
            $ag = $this->strip_agency($param->$par->_value);
            if ($ag)
              $ora_par[$par][] = $ag;
            $param_agencies[$ag] = $param->$par->_value;
          }
          elseif ($param->$par->_value) {
            $ora_par[$par][] = $param->$par->_value;
          }
        }
      }
//var_dump($ora_par);
//var_dump($param_agencies);
//die();
      $cache_key = 'OA_picAL_' . $this->version . 
                                 (is_array($ora_par['agencyId']) ? implode('', $ora_par['agencyId']) : '') . 
                                 (is_array($ora_par['agencyName']) ? implode('', $ora_par['agencyName']) : '') . 
                                 (is_array($ora_par['postalCode']) ? implode('', $ora_par['postalCode']) : '') . 
                                 (is_array($ora_par['city']) ? implode('', $ora_par['city']) : '') . 
                                 $param->pickupAllowed->_value . 
                                 $param->libraryType->_value;
      if ($ret = $this->cache->get($cache_key)) {
        verbose::log(STAT, 'Cache hit');
        return $ret;
      }
      $oci = new Oci($this->config->get_value('agency_credentials','setup'));
      $oci->set_charset('UTF8');
      try {
        $oci->connect();
      }
      catch (ociException $e) {
        verbose::log(FATAL, 'OpenAgency('.__LINE__.'):: OCI connect error: ' . $oci->get_error_string());
        $res->error->_value = 'service_unavailable';
      }
      if (empty($res->error)) {
        if (is_array($ora_par) ||
            $param->libraryType->_value == 'Alle' ||
            $param->libraryType->_value == 'Folkebibliotek' ||
            $param->libraryType->_value == 'Forskningsbibliotek') {
          try {
            if ($ora_par) {
              foreach ($ora_par as $key => $val) {
                $add_sql = '';
                foreach ($val as $par) {
                  $add_item = '';
                  switch ($key) {
                    case 'agencyId':
                      break;
                    case 'agencyName':
                      $add_item .= 'upper(v.navn) like upper(\'%' . $par . '%\')';
                      break;
                    case 'postalCode':
                      $add_item .= 'upper(v.bpostnr) like upper(\'%' . $par . '%\')';
                      break;
                    case 'city':
                      $add_item .= 'upper(v.bcity) like upper(\'%' . $par . '%\')';
                      break;
                  }
                  if ($add_item) {
                    if (empty($add_sql))
                      $add_sql = ' (' . $add_item;
                    else
                      $add_sql .= ' OR ' . $add_item;
                  }
                }
                if ($add_sql) $filter_bib_type .= $add_sql . ')';
              }
            }
            if ($ora_par['agencyId']) {
              foreach ($ora_par['agencyId'] as $agency) {
                $agency_list .= ($agency_list ? ', ' : '') . ':bind_' . $agency;
                $oci->bind('bind_' . $agency, $agency);
              }
              $filter_bib_type .= ' v.bib_nr IN (' . $agency_list . ')';
            }
            elseif (empty($ora_par) && $param->libraryType->_value <> 'Alle') {
              $filter_bib_type .= ' bib_type = :bind_bib_type';
              $oci->bind('bind_bib_type', $param->libraryType->_value);
            }
//var_dump($filter_bib_type);
// 2do vip_beh.best_modt = $param->pickupAllowed->_value
            if ($param->libraryStatus->_value == 'alle') {
              $filter_delete = '';
            } elseif ($param->libraryStatus->_value == 'usynlig') {
              $u = 'U';
              $oci->bind('bind_u', $u);
              $filter_delete = '(vsn.delete_mark_vsn is null or vsn.delete_mark_vsn = :bind_u) AND ';
            } elseif ($param->libraryStatus->_value == 'slettet') {
              $s = 'S';
              $oci->bind('bind_s', $s);
              $filter_delete = '(vsn.delete_mark_vsn is null or vsn.delete_mark_vsn = :bind_s) AND ';
            } else {
              $filter_delete = 'vsn.delete_mark_vsn is null AND ';
            }
            $oci->set_query('SELECT vsn.bib_nr, vsn.navn, vsn.bib_type, vsn.tlf_nr, vsn.email, 
                                    vsn.badr, vsn.bpostnr, vsn.bcity, vsn.url
                            FROM vip_vsn vsn, vip v
                            WHERE ' . $filter_delete . $filter_bib_type . '
                              AND v.bib_vsn = vsn.bib_nr
                            ORDER BY vsn.bib_nr');
            while ($row = $oci->fetch_into_assoc()) {
              $bib_nr = &$row['BIB_NR'];
              $vsn[$bib_nr] = $row;
            }
            $n = 'N';
            $oci->bind('bind_n', $n);
            if ($ora_par['agencyId']) {
              foreach ($ora_par['agencyId'] as $agency) {
                $oci->bind('bind_' . $agency, $agency);
              }
            }
            elseif (empty($ora_par) && $param->libraryType->_value <> 'Alle') {
              $oci->bind('bind_bib_type', $param->libraryType->_value);
            }
            if (isset($param->pickupAllowed->_value)) {
              if ($param->pickupAllowed->_value == 'true' || $param->pickupAllowed->_value == '1')
                $filter_bib_type .= ' AND vb.best_modt = \'J\'';
              else
                $filter_bib_type .= ' AND vb.best_modt != \'J\'';
            }
            if ($param->libraryStatus->_value == 'alle') {
              $filter_delete = '';
            } elseif ($param->libraryStatus->_value == 'usynlig') {
              $u = 'U';
              $oci->bind('bind_u', $u);
              $filter_delete = ' AND (v.delete_mark is null OR v.delete_mark = :bind_u)';
            } elseif ($param->libraryStatus->_value == 'slettet') {
              $s = 'S';
              $oci->bind('bind_s', $s);
              $filter_delete = ' AND (v.delete_mark is null OR v.delete_mark = :bind_s)';
            } else {
              $filter_delete = ' AND v.delete_mark is null';
            }
            $oci->set_query('SELECT v.bib_nr, v.navn, v.type, v.tlf_nr, v.email, v.badr, v.bpostnr, 
                                    v.bcity, v.isil, v.bib_vsn, v.url_homepage, v.url_payment,
                                    vb.best_modt, vb.best_modt_luk, vb.best_modt_luk_eng,
                                    txt.aabn_tid, eng.aabn_tid_e, hold.holdeplads,
                                    bestil.url_serv_dkl,
                                    kat.url_best_blanket, kat.url_laanerstatus
                            FROM vip v, vip_beh vb, vip_txt txt, vip_txt_eng eng, 
                                 vip_bogbus_holdeplads hold, vip_bestil bestil, vip_kat kat
                            WHERE v.bib_vsn IN (SELECT vsn.bib_nr
                                                  FROM vip_vsn vsn, vip v
                                                  WHERE vsn.delete_mark_vsn is null
                                                    AND v.bib_vsn = vsn.bib_nr
                                                    AND ' . $filter_bib_type . ' )
                              ' . $filter_delete . '
                              AND v.bib_nr = vb.bib_nr (+)
                              AND (vb.filial_tf <> :bind_n OR vb.filial_tf is null)
                              AND v.bib_nr = txt.bib_nr (+)
                              AND v.bib_nr = hold.bib_nr (+)
                              AND v.bib_nr = eng.bib_nr (+)
                              AND v.bib_nr = bestil.bib_nr (+)
                              AND v.bib_nr = kat.bib_nr (+)
                            ORDER BY v.bib_vsn, v.bib_nr');
            while ($row = $oci->fetch_into_assoc()) {
              if ($ora_par['agencyId']) {
                $a_key = array_search($row['BIB_NR'], $ora_par['agencyId']);
                if (is_int($a_key)) unset($ora_par['agencyId'][$a_key]);
              }
              $this_vsn = $row['BIB_VSN'];
              if ($library && $library->agencyId->_value <> $this_vsn) {
                $library->pickupAgency[]->_value = $pickupAgency;
                unset($pickupAgency);
                $res->library[]->_value = $library;
                unset($library);
              }
              if (empty($library)) {
                $library->agencyId->_value = $this_vsn;
                $library->agencyType->_value = $vsn[$this_vsn]['BIB_TYPE'];
                $library->agencyName->_value = $vsn[$this_vsn]['NAVN'];
                if ($vsn[$this_vsn]['TLF_NR']) $library->agencyPhone->_value = $vsn[$this_vsn]['TLF_NR'];
                if ($vsn[$this_vsn]['EMAIL']) $library->agencyEmail->_value = $vsn[$this_vsn]['EMAIL'];
                if ($vsn[$this_vsn]['BADR']) $library->postalAddress->_value = $vsn[$this_vsn]['BADR'];
                if ($vsn[$this_vsn]['BPOSTNR']) $library->postalCode->_value = $vsn[$this_vsn]['BPOSTNR'];
                if ($vsn[$this_vsn]['BCITY']) $library->city->_value = $vsn[$this_vsn]['BCITY'];
                if ($vsn[$this_vsn]['URL']) $library->agencyWebsiteUrl->_value = $vsn[$this_vsn]['URL'];
              }
              if ($pickupAgency && $pickupAgency->branchId->_value <> $row['BIB_NR']) {
                $library->pickupAgency[]->_value = $pickupAgency;
                unset($pickupAgency);
              }
              if (empty($pickupAgency)) {
                $pickupAgency->branchId->_value = $row['BIB_NR'];
                $pickupAgency->branchType->_value = $row['TYPE'];
                $pickupAgency->branchPhone->_value = $row['TLF_NR'];
                $pickupAgency->branchName->_value = $row['NAVN'];
                $pickupAgency->branchEmail->_value = $row['EMAIL'];
                if ($row['BADR']) $pickupAgency->postalAddress->_value = $row['BADR'];
                if ($row['BPOSTNR']) $pickupAgency->postalCode->_value = $row['BPOSTNR'];
                if ($row['BCITY']) $pickupAgency->city->_value = $row['BCITY'];
                if ($row['ISIL']) $pickupAgency->isil->_value = $row['ISIL'];
                if ($row['URL_HOMEPAGE']) $pickupAgency->branchWebsiteUrl->_value = $row['URL_HOMEPAGE'];
                if ($row['URL_SERV_DKL']) $pickupAgency->serviceDeclarationUrl->_value = $row['URL_SERV_DKL'];
                if ($row['URL_BEST_BLANKET']) $pickupAgency->registrationFormUrl->_value = $row['URL_BEST_BLANKET'];
                if ($row['URL_PAYMENT']) $pickupAgency->paymentUrl->_value = $row['URL_PAYMENT'];
                if ($row['URL_LAANERSTATUS']) $pickupAgency->userStatusUrl->_value = $row['URL_LAANERSTATUS'];
              }
              if ($row['HOLDEPLADS'])
                $pickupAgency->agencySubdivision[]->_value = $row['HOLDEPLADS'];
              if (empty($pickupAgency->openingHours)
                  && ($row['AABN_TID'] || $row['AABN_TID_E'])) {
                if ($row['AABN_TID']) {
                  $help->_value = $row['AABN_TID'];
                  $help->_attributes->language->_value = 'dan';
                  $pickupAgency->openingHours[] = $help;
                  unset($help);
                }
                if ($row['AABN_TID_E']) {
                  $help->_value = $row['AABN_TID_E'];
                  $help->_attributes->language->_value = 'eng';
                  $pickupAgency->openingHours[] = $help;
                  unset($help);
                }
              }
              $pickupAgency->temporarilyClosed->_value = ($row['BEST_MODT'] == 'J' ? 'false' : 'true');
              if ($row['BEST_MODT'] == 'L'
                  && empty($pickupAgency->temporarilyClosedReason)
                  && ($row['BEST_MODT_LUK'] || $row['BEST_MODT_LUK_ENG'])) {
                if ($row['BEST_MODT_LUK']) {
                  $help->_value = $row['BEST_MODT_LUK'];
                  $help->_attributes->language->_value = 'dan';
                  $pickupAgency->temporarilyClosedReason[] = $help;
                  unset($help);
                }
                if ($row['BEST_MODT_LUK_ENG']) {
                  $help->_value = $row['BEST_MODT_LUK_ENG'];
                  $help->_attributes->language->_value = 'eng';
                  $pickupAgency->temporarilyClosedReason[] = $help;
                  unset($help);
                }
              }
              $pickupAgency->pickupAllowed->_value = ($row['BEST_MODT'] == 'J' ? '1' : '0');
            }
            if ($pickupAgency) {
              $library->pickupAgency[]->_value = $pickupAgency;
            }
            if ($library) {
              $res->library[]->_value = $library;
              if ($ora_par['agencyId']) {
                foreach ($ora_par['agencyId'] as $agency) {
                  $help->agencyId->_value = $param_agencies[$agency];
                  $help->error->_value = 'agency_not_found';
                  $res->library[]->_value = $help;
                  unset($help);
                }
              }
            } else {
              $res->error->_value = 'no_agencies_found';
            }
          }
          catch (ociException $e) {
            verbose::log(FATAL, 'OpenAgency('.__LINE__.'):: OCI select error: ' . $oci->get_error_string());
            $res->error->_value = 'service_unavailable';
          }
        }
        else
          $res->error->_value = 'error_in_request';
      }
    }
    //var_dump($res); var_dump($param); die();
    $ret->pickupAgencyListResponse->_value = $res;
    $ret = $this->objconvert->set_obj_namespace($ret, $this->xmlns['oa']);
    if (empty($res->error)) $this->cache->set($cache_key, $ret);
    return $ret;
  }

  /** \brief
   *
   * Request:
   * - agencyId
   * - profileName: 
   * - profileVersion: 
   * Response:
   * - profile
   * - - profileName
   * - - source
   * - - - sourceName
   * - - - sourceIdentifier
   * - - - 1 above or 2 below
   * - - - sourceOwner
   * - - - sourceFormat
   * - - - relation
   * - - - - rdfLabel
   * - - - - rdfInverse
   */
  public function openSearchProfile($param) {
    if (!$this->aaa->has_right('openagency', 500))
      $res->error->_value = 'authentication_error';
    else {
      $agency = $this->strip_agency($param->agencyId->_value);
      $cache_key = 'OA_opeSP_' . $this->version . $agency . $param->profileName->_value . $param->profileVersion->_value;
      if ($ret = $this->cache->get($cache_key)) {
        verbose::log(STAT, 'Cache hit');
        return $ret;
      }
      $oci = new Oci($this->config->get_value('agency_credentials','setup'));
      $oci->set_charset('UTF8');
      try {
        $oci->connect();
      }
      catch (ociException $e) {
        verbose::log(FATAL, 'OpenAgency('.__LINE__.'):: OCI connect error: ' . $oci->get_error_string());
        $res->error->_value = 'service_unavailable';
      }
      if (empty($res->error)) {
        if ($param->profileVersion->_value == 3) {
          try {
// hent alle broend_to_kilder med searchable == "Y" og loop over dem.
// find de kilder som profilen kender:
// Søgbarheden (sourceSearchable) gives hvis den findes i den givne profil (broendkilde_id findes)
// Søgbargeden kan evt. begrænses af broend_to_kilder.access_for
            $oci->bind('bind_y', 'Y');
            $oci->set_query('SELECT DISTINCT *
                               FROM broend_to_kilder
                              WHERE searchable = :bind_y
                               ORDER BY upper(name)');
            $kilder = $oci->fetch_all_into_assoc();
            $oci->bind('bind_agency', $agency);
            if ($profile = strtolower($param->profileName->_value)) {
              $oci->bind('bind_profile', $profile);
              $sql_add = ' AND lower(broend_to_profiler.name) = :bind_profile';
            }
            $oci->set_query('SELECT broendkilde_id, profil_id, name
                               FROM broendprofil_to_kilder, broend_to_profiler
                              WHERE broend_to_profiler.bib_nr = :bind_agency
                                AND  broend_to_profiler.id_nr = broendprofil_to_kilder.profil_id (+)' . $sql_add);
            $profil_res = $oci->fetch_all_into_assoc();
            foreach ($profil_res as $p) {
              $profiler[$p['PROFIL_ID']][$p['BROENDKILDE_ID']] = $p;
            }
//var_dump($kilder);
            foreach ($profiler as $profil_no => $profil) {
//echo 'Profil: '; var_dump($profil);
              foreach ($kilder as $kilde) {
                if (empty($kilde['ACCESS_FOR']) || strpos($kilde['ACCESS_FOR'], $agency) !== FALSE) {
                  $oci->bind('bind_kilde_id', $kilde['ID_NR']);
                  $oci->set_query('SELECT DISTINCT rdf, rdf_reverse
                                     FROM broend_relation, broend_kilde_relation, broend_profil_kilde_relation
                                    WHERE broend_kilde_relation.broendkilde_id = :bind_kilde_id 
                                      AND broend_profil_kilde_relation.kilde_relation_id =  broend_kilde_relation.id_nr 
                                      AND broend_kilde_relation.relation_id = broend_relation.id_nr');
                  $relations = $oci->fetch_all_into_assoc();
//echo $kilde['NAME'] . ': '; var_dump($relations);
                  $s->sourceName->_value = $kilde['NAME'];
                  if (isset($profil[$kilde['ID_NR']])) {
                    $profile_name = $profil[$kilde['ID_NR']]['NAME'];
                    $s->sourceSearchable->_value = '1';
                  }
                  else
                    $s->sourceSearchable->_value = '0';
                  $s->sourceIdentifier->_value = str_replace('[agency]', $agency, $kilde['IDENTIFIER']);
                  if ($relations) {
                    foreach ($relations as $relation) {
                      $rel->rdfLabel->_value = $relation['RDF'];
                      if ($relation['RDF_REVERSE'])
                        $rel->rdfInverse->_value = $relation['RDF_REVERSE'];
                      $s->relation[]->_value = $rel;
                      unset($rel);
                    }
                  }
                }
                $res->profile[$profil_no]->_value->profileName->_value = $profile_name;
                if ($s) {
                  $res->profile[$profil_no]->_value->source[]->_value = $s;
                  unset($s);
                }
              }
            }
//var_dump($res);
//die();
/*
            $oci->set_query('SELECT DISTINCT broend_to_profiler.name bp_name,
                                             broend_to_kilder.name, broend_to_kilder.id_nr b2k_id_nr,
                                             identifier, access_for, status, searchable
                               FROM broend_to_kilder, broend_to_profiler, broendprofil_to_kilder
                              WHERE broend_to_kilder.id_nr = broendprofil_to_kilder.broendkilde_id
                                AND broendprofil_to_kilder.profil_id = broend_to_profiler.id_nr
                                AND broend_to_profiler.bib_nr = :bind_agency' . $sql_add);
            $profiles = $oci->fetch_all_into_assoc();
//var_dump($profiles);
            foreach ($profiles as $profile) {
              $oci->bind('bind_kilde_id', $profile['B2K_ID_NR']);
              $oci->set_query('SELECT DISTINCT rdf, rdf_reverse
                                 FROM broend_relation, broend_kilde_relation, broend_profil_kilde_relation
                                WHERE broend_kilde_relation.broendkilde_id = :bind_kilde_id 
                                  AND broend_profil_kilde_relation.kilde_relation_id =  broend_kilde_relation.id_nr 
                                  AND broend_kilde_relation.relation_id = broend_relation.id_nr');
              $relations = $oci->fetch_all_into_assoc();
//var_dump($relations);
              if (empty($profile['ACCESS_FOR']) ||  strpos($profile['ACCESS_FOR'], $agency) !== FALSE) {
                $s->sourceName->_value = $profile['NAME'];
                $s->sourceSearchable->_value = '?';
                $s->sourceIdentifier->_value = str_replace('[agency]', $agency, $profile['IDENTIFIER']);
                if ($relations) {
                  foreach ($relations as $relation) {
                    $rel->rdfLabel->_value = $relation['RDF'];
                    if ($relation['RDF_REVERSE'])
                      $rel->rdfInverse->_value = $relation['RDF_REVERSE'];
                    $s->relation[]->_value = $rel;
                    unset($rel);
                  }
                }
                $res->profile[$profile['BP_NAME']]->_value->profileName->_value = $profile['BP_NAME'];
                $res->profile[$profile['BP_NAME']]->_value->source[]->_value = $s;
                unset($s);
              }
            }
*/
//die('a');
          }
          catch (ociException $e) {
            verbose::log(FATAL, 'OpenAgency('.__LINE__.'):: OCI select error: ' . $oci->get_error_string());
            $res->error->_value = 'service_unavailable';
          }
        } else {
          $oci->bind('bind_agency', $agency);
          if ($profile = strtolower($param->profileName->_value)) {
            $oci->bind('bind_profile', $profile);
            $sql_add = ' AND lower(broendprofiler.name) = :bind_profile';
          }
          try {
            $oci->set_query('SELECT DISTINCT broendprofiler.name bp_name,
                                             broendkilder.name, submitter, format
                               FROM broendkilder, broendprofil_kilder, broendprofiler
                              WHERE broendkilder.id_nr = broendprofil_kilder.broendkilde_id
                                AND broendprofil_kilder.profil_id = broendprofiler.id_nr
                                AND broendprofiler.bib_nr = :bind_agency' . $sql_add);
            while ($s_row = $oci->fetch_into_assoc()) {
              $s->sourceName->_value = $s_row['NAME'];
              $s->sourceOwner->_value = (strtolower($s_row['SUBMITTER']) == 'agency' ? $agency : $s_row['SUBMITTER']);
              $s->sourceFormat->_value = $s_row['FORMAT'];
              $res->profile[$s_row['BP_NAME']]->_value->profileName->_value = $s_row['BP_NAME'];
              $res->profile[$s_row['BP_NAME']]->_value->source[]->_value = $s;
              unset($s);
            }
          }
          catch (ociException $e) {
            verbose::log(FATAL, 'OpenAgency('.__LINE__.'):: OCI select error: ' . $oci->get_error_string());
            $res->error->_value = 'service_unavailable';
          }
        }
      }
    }
    //var_dump($res); var_dump($param); die();
    $ret->openSearchProfileResponse->_value = $res;
    $ret = $this->objconvert->set_obj_namespace($ret, $this->xmlns['oa']);
    if (empty($res->error)) $this->cache->set($cache_key, $ret);
    return $ret;
  }


  /** \brief
   *
   * Request:
   * - agencyId
   * Response:
   * - agencyId
   * - subscription
   * - - name
   * - - url
   * - or
   * - - error
   */
  public function remoteAccess($param) {
    if (!$this->aaa->has_right('openagency', 550))
      $res->error->_value = 'authentication_error';
    else {
      $agency = $this->strip_agency($param->agencyId->_value);
      $cache_key = 'OA_remA_' . $this->version . $agency;
      if ($ret = $this->cache->get($cache_key)) {
        verbose::log(STAT, 'Cache hit');
        return $ret;
      }
      $oci = new Oci($this->config->get_value('agency_credentials','setup'));
      $oci->set_charset('UTF8');
      try {
        $oci->connect();
      }
      catch (ociException $e) {
        verbose::log(FATAL, 'OpenAgency('.__LINE__.'):: OCI connect error: ' . $oci->get_error_string());
        $res->error->_value = 'service_unavailable';
      }
      if (empty($res->error)) {
        try {
          $oci->bind('bind_agency', $agency);
          $uno = '1';
          $oci->bind('bind_har_adgang', $uno);
          $oci->set_query('SELECT fjernadgang_licenser.navn "licens_navn",
                          fjernadgang_licenser.url "licens_url",
                          fjernadgang_dbc.navn "dbc_navn",
                          fjernadgang_dbc.url "dbc_url",
                          fjernadgang_dbc.har_fjernadgang "dbc_har_fjernadgang",
                          fjernadgang_andre.navn "andre_navn",
                          fjernadgang_andre.url "andre_url",
                          fjernadgang_andre.har_fjernadgang "andre_har_fjernadgang",
                          fjernadgang.har_adgang,
                          fjernadgang.faust,
                          fjernadgang.url,
                          autolink
                          FROM fjernadgang, fjernadgang_licenser, fjernadgang_dbc, fjernadgang_andre, licensguide
                          WHERE fjernadgang.bib_nr = :bind_agency
                          AND fjernadgang.type = :bind_har_adgang
                          AND fjernadgang.faust = fjernadgang_licenser.faust (+)
                          AND fjernadgang.faust = fjernadgang_dbc.faust (+)
                          AND fjernadgang.faust = fjernadgang_andre.faust (+)
                          AND fjernadgang.bib_nr = licensguide.bib_nr (+)');
          $buf = $oci->fetch_all_into_assoc();
//if ($this->debug) die('<pre>' . print_r($buf, TRUE));
          $res->agencyId->_value = $param->agencyId->_value;
          foreach ($buf as $val) {
            if ($s->name->_value = $val['licens_navn']) {
              if ($val['AUTOLINK']) {
                $s->url->_value = $val['AUTOLINK'];
              }
              else {
                $s->url->_value = ($val['URL'] ? $val['URL'] : $val['licens_url']);
              }
            }
            elseif ($s->name->_value = $val['dbc_navn']) {
              $s->url->_value = ($val['URL'] ? $val['URL'] : $val['dbc_url']);
            }
            elseif ($s->name->_value = $val['andre_navn']) {
              $s->url->_value = ($val['URL'] ? $val['URL'] : $val['andre_url']);
            }
            if ($s->url->_value && $val['FAUST'] <> 1234567) {    // drop eBib
              if ($val['URL'])
                $s->url->_value = str_replace('[URL_FJERNADGANG]', $val['URL'], $s->url->_value);
              else
                $s->url->_value = str_replace('[URL_FJERNADGANG]', $val['licens_url'], $s->url->_value);
              $s->url->_value = str_replace('[LICENS_ID]', $val['FAUST'], $s->url->_value);
              $res->subscription[]->_value = $s;
            }
            unset($s);
          }
        }
        catch (ociException $e) {
          verbose::log(FATAL, 'OpenAgency('.__LINE__.'):: OCI select error: ' . $oci->get_error_string());
          $res->error->_value = 'service_unavailable';
        }
      }
    }
    //var_dump($res); var_dump($param); die();
    $ret->remoteAccessResponse->_value = $res;
    $ret = $this->objconvert->set_obj_namespace($ret, $this->xmlns['oa']);
    if (empty($res->error)) $this->cache->set($cache_key, $ret);
    return $ret;
  }


  /** \brief
   *
   * Request:
   * - agencyId
   * Response:
   * - autPotential
   * or
   * - autProvider
   * or
   * - autRequester
   * or
   * - error
   */
  public function requestOrder($param) {
    if (!$this->aaa->has_right('openagency', 500))
      $res->error->_value = 'authentication_error';
    else {
      $agency = $this->strip_agency($param->agencyId->_value);
      $cache_key = 'OA_reqO_' . $this->version . $agency;
      if ($ret = $this->cache->get($cache_key)) {
        verbose::log(STAT, 'Cache hit');
        return $ret;
      }
      $oci = new Oci($this->config->get_value('agency_credentials','setup'));
      $oci->set_charset('UTF8');
      try {
        $oci->connect();
      }
      catch (ociException $e) {
        verbose::log(FATAL, 'OpenAgency('.__LINE__.'):: OCI connect error: ' . $oci->get_error_string());
        $res->error->_value = 'service_unavailable';
      }
      if (empty($res->error)) {
        try {
          $oci->bind('bind_agency', $agency);
          $oci->set_query('SELECT vilse 
                          FROM vip, laaneveje
                          WHERE (vip.bib_vsn = bibliotek OR vip.bib_nr = bibliotek)
                            AND vip.bib_nr = :bind_agency
                          ORDER BY prionr DESC');
          $prio = array();
          while ($s_row = $oci->fetch_into_assoc()) {
            $res->agencyId[]->_value = $s_row['VILSE'];
          }
          if (empty($res->agencyId))
            $res->error->_value = 'no_agencies_found';
        }
        catch (ociException $e) {
          verbose::log(FATAL, 'OpenAgency('.__LINE__.'):: OCI select error: ' . $oci->get_error_string());
          $res->error->_value = 'service_unavailable';
        }
      }
    }
    //var_dump($res); var_dump($param); die();
    $ret->requestOrderResponse->_value = $res;
    $ret = $this->objconvert->set_obj_namespace($ret, $this->xmlns['oa']);
    if (empty($res->error)) $this->cache->set($cache_key, $ret);
    return $ret;
  }



  /** \brief Echos config-settings
   *
   */
  public function show_info() {
    echo '<pre>';
    echo 'version             ' . $this->config->get_value('version', 'setup') . '<br/>';
    echo 'logfile             ' . $this->config->get_value('logfile', 'setup') . '<br/>';
    echo 'verbose             ' . $this->config->get_value('verbose', 'setup') . '<br/>';
    echo 'agency_credentials  ' . $this->strip_oci_pwd($this->config->get_value('agency_credentials', 'setup')) . '<br/>';
    echo 'aaa_credentials     ' . $this->strip_oci_pwd($this->config->get_value('aaa_credentials', 'aaa')) . '<br/>';
    echo '</pre>';
    die();
  }

  private function strip_oci_pwd($cred) {
    if (($p1 = strpos($cred, '/')) && ($p2 = strpos($cred, '@')))
      return substr($cred, 0, $p1) . '/********' . substr($cred, $p2);
    else
      return $cred;
  }


  /** \brief
   *  return libraryno - align to 6 digits
   */
  private function normalize_agency($id) {
    if (is_numeric($id))
      return sprintf('%06s', $id);
    else
      return $id;
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
    if (is_array($arr)) {
      foreach ($arr as $key => $val) {
        if (is_scalar($val))
          $arr[$key] = str_replace("\r", ' ', str_replace("\n", ' ', $val));
      }
    }
  }

}

/**
 *   MAIN
 */

$ws=new openAgency();
$ws->handle_request();

?>
