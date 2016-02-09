<?php
/*
** Zabbix
** Copyright (C) 2001-2016 Zabbix SIA
**
** This program is free software; you can redistribute it and/or modify
** it under the terms of the GNU General Public License as published by
** the Free Software Foundation; either version 2 of the License, or
** (at your option) any later version.
**
** This program is distributed in the hope that it will be useful,
** but WITHOUT ANY WARRANTY; without even the implied warranty of
** MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
** GNU General Public License for more details.
**
** You should have received a copy of the GNU General Public License
** along with this program; if not, write to the Free Software
** Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
**/


/**
 * Class containing methods for operations with discovery rules.
 *
 * @package API
 */
class CDRule extends CApiService {

	protected $tableName = 'drules';
	protected $tableAlias = 'dr';
	protected $sortColumns = ['druleid', 'name'];

	/**
	 * Get drule data.
	 *
	 * @param array $options
	 *
	 * @return array
	 */
	public function get(array $options = []) {
		$result = [];

		$sqlParts = [
			'select'	=> ['drules' => 'dr.druleid'],
			'from'		=> ['drules' => 'drules dr'],
			'where'		=> [],
			'group'		=> [],
			'order'		=> [],
			'limit'		=> null
		];

		$defOptions = [
			'druleids'					=> null,
			'dhostids'					=> null,
			'dserviceids'				=> null,
			'editable'					=> null,
			'selectDHosts'				=> null,
			'selectDChecks'				=> null,
			// filter
			'filter'					=> null,
			'search'					=> null,
			'searchByAny'				=> null,
			'startSearch'				=> null,
			'excludeSearch'				=> null,
			'searchWildcardsEnabled'	=> null,
			// output
			'output'					=> API_OUTPUT_EXTEND,
			'countOutput'				=> null,
			'groupCount'				=> null,
			'preservekeys'				=> null,
			'sortfield'					=> '',
			'sortorder'					=> '',
			'limit'						=> null,
			'limitSelects'				=> null
		];
		$options = zbx_array_merge($defOptions, $options);

		if (CWebUser::getType() < USER_TYPE_ZABBIX_ADMIN) {
			return [];
		}

// druleids
		if (!is_null($options['druleids'])) {
			zbx_value2array($options['druleids']);
			$sqlParts['where']['druleid'] = dbConditionInt('dr.druleid', $options['druleids']);
		}

// dhostids
		if (!is_null($options['dhostids'])) {
			zbx_value2array($options['dhostids']);

			$sqlParts['from']['dhosts'] = 'dhosts dh';
			$sqlParts['where']['dhostid'] = dbConditionInt('dh.dhostid', $options['dhostids']);
			$sqlParts['where']['dhdr'] = 'dh.druleid=dr.druleid';

			if (!is_null($options['groupCount'])) {
				$sqlParts['group']['dhostid'] = 'dh.dhostid';
			}
		}

// dserviceids
		if (!is_null($options['dserviceids'])) {
			zbx_value2array($options['dserviceids']);

			$sqlParts['from']['dhosts'] = 'dhosts dh';
			$sqlParts['from']['dservices'] = 'dservices ds';

			$sqlParts['where']['dserviceid'] = dbConditionInt('ds.dserviceid', $options['dserviceids']);
			$sqlParts['where']['dhdr'] = 'dh.druleid=dr.druleid';
			$sqlParts['where']['dhds'] = 'dh.dhostid=ds.dhostid';

			if (!is_null($options['groupCount'])) {
				$sqlParts['group']['dserviceid'] = 'ds.dserviceid';
			}
		}

// search
		if (!is_null($options['search'])) {
			zbx_db_search('drules dr', $options, $sqlParts);
		}

// filter
		if (is_array($options['filter'])) {
			$this->dbFilter('drules dr', $options, $sqlParts);
		}

// search
		if (is_array($options['search'])) {
			zbx_db_search('drules dr', $options, $sqlParts);
		}

// limit
		if (zbx_ctype_digit($options['limit']) && $options['limit']) {
			$sqlParts['limit'] = $options['limit'];
		}
//------------

		$sqlParts = $this->applyQueryOutputOptions($this->tableName(), $this->tableAlias(), $options, $sqlParts);
		$sqlParts = $this->applyQuerySortOptions($this->tableName(), $this->tableAlias(), $options, $sqlParts);
		$dbRes = DBselect($this->createSelectQueryFromParts($sqlParts), $sqlParts['limit']);
		while ($drule = DBfetch($dbRes)) {
			if (!is_null($options['countOutput'])) {
				if (!is_null($options['groupCount']))
					$result[] = $drule;
				else
					$result = $drule['rowscount'];
			}
			else {
				$result[$drule['druleid']] = $drule;
			}
		}

		if (!is_null($options['countOutput'])) {
			return $result;
		}

		if ($result) {
			$result = $this->addRelatedObjects($options, $result);
		}

// removing keys (hash -> array)
		if (is_null($options['preservekeys'])) {
			$result = zbx_cleanHashes($result);
		}

	return $result;
	}

	/**
	 * Validate the input parameters for create() method.
	 *
	 * @param array $drules		Discovery rules data.
	 *
	 * @throws APIException if the input is invalid.
	 */
	protected function validateCreate(array $drules) {
		// Check permissions.
		if (self::$userData['type'] == USER_TYPE_ZABBIX_USER) {
			self::exception(ZBX_API_ERROR_PARAMETERS, _('No permissions to referred object or it does not exist!'));
		}

		if (!$drules) {
			self::exception(ZBX_API_ERROR_PARAMETERS, _('Empty input parameter.'));
		}

		$proxies = [];

		$ip_range_validator = new CIPRangeValidator(['ipRangeLimit' => ZBX_DISCOVERER_IPRANGE_LIMIT]);

		foreach ($drules as $drule) {
			if (!array_key_exists('name', $drule)) {
				self::exception(ZBX_API_ERROR_PARAMETERS, _('Field "name" is required.'));
			}
			elseif (is_array($drule['name'])) {
				self::exception(ZBX_API_ERROR_PARAMETERS, _('Incorrect arguments passed to function.'));
			}
			elseif ($drule['name'] === '' || $drule['name'] === null || $drule['name'] === false) {
				self::exception(ZBX_API_ERROR_PARAMETERS,
					_s('Incorrect value for field "%1$s": %2$s.', 'name', _('cannot be empty'))
				);
			}

			if (!array_key_exists('iprange', $drule)) {
				self::exception(ZBX_API_ERROR_PARAMETERS,
					_s('Incorrect value for field "%1$s": %2$s.', 'iprange', _('cannot be empty'))
				);
			}
			elseif (!$ip_range_validator->validate($drule['iprange'])) {
				self::exception(ZBX_API_ERROR_PARAMETERS, $ip_range_validator->getError());
			}

			if (array_key_exists('delay', $drule)) {
				if (is_array($drule['delay'])) {
					self::exception(ZBX_API_ERROR_PARAMETERS, _('Incorrect arguments passed to function.'));
				}
				elseif ($drule['delay'] < 1 || $drule['delay'] > SEC_PER_WEEK) {
					self::exception(ZBX_API_ERROR_PARAMETERS,
						_s('Incorrect value "%1$s" for "%2$s" field.', $drule['delay'], 'delay')
					);
				}
			}

			if (array_key_exists('status', $drule) && $drule['status'] != DRULE_STATUS_DISABLED
					&& $drule['status'] != DRULE_STATUS_ACTIVE) {
				self::exception(ZBX_API_ERROR_PARAMETERS,
					_s('Incorrect value "%1$s" for "%2$s" field.', $drule['status'], 'status')
				);
			}

			if (array_key_exists('dchecks', $drule) && $drule['dchecks']) {
				$this->validateDChecks($drule['dchecks']);
			}
			else {
				self::exception(ZBX_API_ERROR_PARAMETERS, _('Cannot save discovery rule without checks.'));
			}

			if (array_key_exists('proxy_hostid', $drule) && $drule['proxy_hostid']) {
				$proxies[] = $drule['proxy_hostid'];
			}
		}

		// Check host name duplicates.
		$dublicate_names = CArrayHelper::findDuplicate($drules, 'name');
		if ($dublicate_names) {
			self::exception(ZBX_API_ERROR_PARAMETERS,
				_s('Discovery rule "%1$s" already exists.', $dublicate_names['name'])
			);
		}

		// Checking to the duplicate names.
		$db_dublicate_names = API::getApiService()->select($this->tableName(), [
			'output' => ['name'],
			'filter' => ['name' => zbx_objectValues($drules, 'name')],
			'limit' => 1
		]);

		if ($db_dublicate_names) {
			$db_dublicate_name = reset($db_dublicate_names);
			self::exception(ZBX_API_ERROR_PARAMETERS,
				_s('Discovery rule "%1$s" already exists.', $db_dublicate_name['name'])
			);
		}

		if ($proxies) {
			$db_proxies = API::proxy()->get([
				'output' => ['proxyid'],
				'proxyids' => $proxies,
				'preservekeys' => true
			]);
			foreach ($proxies as $proxy) {
				if (!array_key_exists($proxy, $db_proxies)) {
					self::exception(ZBX_API_ERROR_PARAMETERS, _('Incorrect proxyid.'));
				}
			}
		}
	}

	/**
	 * Validate the input parameters for update() method.
	 *
	 * @param array $drules			Discovery rules data.
	 * @param array $db_drules		DB discovery rules data.
	 *
	 * @throws APIException if the input is invalid.
	 */
	protected function validateUpdate(array $drules, array $db_drules) {
		// Check permissions.
		if (self::$userData['type'] == USER_TYPE_ZABBIX_USER) {
			self::exception(ZBX_API_ERROR_PARAMETERS, _('No permissions to referred object or it does not exist!'));
		}

		if (!$drules) {
			self::exception(ZBX_API_ERROR_PARAMETERS, _('Empty input parameter.'));
		}

		$proxies = [];
		$drule_names_changed = [];

		$ip_range_validator = new CIPRangeValidator(['ipRangeLimit' => ZBX_DISCOVERER_IPRANGE_LIMIT]);

		foreach ($drules as $drule) {
			if (!array_key_exists('druleid', $drule)) {
				self::exception(ZBX_API_ERROR_PARAMETERS, _('Field "druleid" is required.'));
			}
			elseif (!array_key_exists($drule['druleid'], $db_drules)) {
				self::exception(ZBX_API_ERROR_PARAMETERS, _('No permissions to referred object or it does not exist!'));
			}

			if (array_key_exists('name', $drule)) {
				if (is_array($drule['name'])) {
					self::exception(ZBX_API_ERROR_PARAMETERS, _('Incorrect arguments passed to function.'));
				}
				elseif ($drule['name'] === '' || $drule['name'] === null || $drule['name'] === false) {
					self::exception(ZBX_API_ERROR_PARAMETERS,
						_s('Incorrect value for field "%1$s": %2$s.', 'name', _('cannot be empty'))
					);
				}

				if ($db_drules[$drule['druleid']]['name'] !== $drule['name']) {
					if (array_key_exists($drule['name'], $drule_names_changed)) {
						self::exception(ZBX_API_ERROR_PARAMETERS,
							_s('Discovery rule "%1$s" already exists.', $drule['name'])
						);
					}
					else {
						$drule_names_changed[$drule['name']] = $drule['name'];
					}
				}
			}

			if (array_key_exists('iprange', $drule) && !$ip_range_validator->validate($drule['iprange'])) {
				self::exception(ZBX_API_ERROR_PARAMETERS, $ip_range_validator->getError());
			}

			if (array_key_exists('delay', $drule)) {
				if (is_array($drule['delay'])) {
					self::exception(ZBX_API_ERROR_PARAMETERS, _('Incorrect arguments passed to function.'));
				}
				elseif ($drule['delay'] < 1 || $drule['delay'] > SEC_PER_WEEK) {
					self::exception(ZBX_API_ERROR_PARAMETERS,
						_s('Incorrect value "%1$s" for "%2$s" field.', $drule['delay'], 'delay')
					);
				}
			}

			if (array_key_exists('status', $drule) && $drule['status'] != DRULE_STATUS_DISABLED
					&& $drule['status'] != DRULE_STATUS_ACTIVE) {
				self::exception(ZBX_API_ERROR_PARAMETERS,
					_s('Incorrect value "%1$s" for "%2$s" field.', $drule['status'], 'status')
				);
			}

			if (array_key_exists('dchecks', $drule)) {
				if ($drule['dchecks']) {
					$this->validateDChecks($drule['dchecks']);
				}
				else {
					self::exception(ZBX_API_ERROR_PARAMETERS, _('Cannot save discovery rule without checks.'));
				}
			}

			if (array_key_exists('proxy_hostid', $drule) && $drule['proxy_hostid']) {
				$proxies[] = $drule['proxy_hostid'];
			}
		}

		// Validate drule duplicate names.
		if ($drule_names_changed) {
			$dublicate_names = API::getApiService()->select($this->tableName(), [
				'output' => ['name'],
				'filter' => ['name' => $drule_names_changed],
				'limit' => 1
			]);

			if ($dublicate_names) {
				$dublicate_name = reset($dublicate_names);
				self::exception(ZBX_API_ERROR_PARAMETERS, _s('Discovery rule "%1$s" already exists.',
					$dublicate_name['name']
				));
			}
		}

		if ($proxies) {
			$db_proxies = API::proxy()->get([
				'output' => ['proxyid'],
				'proxyids' => $proxies,
				'preservekeys' => true
			]);
			foreach ($proxies as $proxy) {
				if (!array_key_exists($proxy, $db_proxies)) {
					self::exception(ZBX_API_ERROR_PARAMETERS, _('Incorrect proxyid.'));
				}
			}
		}
	}

	protected function validateDChecks(array $dchecks) {
		$uniq = 0;
		$item_key_parser = new CItemKey();

		foreach ($dchecks as $dcnum => $dcheck) {
			if (array_key_exists('uniq', $dcheck) && ($dcheck['uniq'] == 1)) {
				if (!in_array($dcheck['type'], [SVC_AGENT, SVC_SNMPv1, SVC_SNMPv2c, SVC_SNMPv3])) {
					self::exception(ZBX_API_ERROR_PARAMETERS,
						_('Only Zabbix agent, SNMPv1, SNMPv2 and SNMPv3 checks can be made unique.')
					);
				}

				$uniq++;
			}

			if (array_key_exists('ports', $dcheck) && !validate_port_list($dcheck['ports'])) {
				self::exception(ZBX_API_ERROR_PARAMETERS, _s('Incorrect port range.'));
			}

			$dcheck_types = [SVC_SSH, SVC_LDAP, SVC_SMTP, SVC_FTP, SVC_HTTP, SVC_POP, SVC_NNTP, SVC_IMAP, SVC_TCP,
				SVC_AGENT, SVC_SNMPv1, SVC_SNMPv2c, SVC_ICMPPING, SVC_SNMPv3, SVC_HTTPS, SVC_TELNET
			];

			if (!array_key_exists('type', $dcheck)) {
				self::exception(ZBX_API_ERROR_PARAMETERS, _s('Field "%1$s" is mandatory.', 'type'));
			}
			elseif (!is_numeric($dcheck['type']) || !in_array($dcheck['type'], $dcheck_types)) {
				self::exception(ZBX_API_ERROR_PARAMETERS, _s('Incorrect value for field "%1$s".', 'type'));
			}

			switch ($dcheck['type']) {
				case SVC_AGENT:
					if (!array_key_exists('key_', $dcheck) || $dcheck['key_'] === null) {
						self::exception(ZBX_API_ERROR_PARAMETERS, _('Incorrect key.'));
					}

					if ($item_key_parser->parse($dcheck['key_']) != CParser::PARSE_SUCCESS) {
						self::exception(ZBX_API_ERROR_PARAMETERS,
							_s('Invalid key "%1$s": %2$s.', $dcheck['key_'], $item_key_parser->getError())
						);
					}
					break;
				case SVC_SNMPv1:
				case SVC_SNMPv2c:
					if (!array_key_exists('snmp_community', $dcheck) || $dcheck['snmp_community'] === null
							|| $dcheck['snmp_community'] === false || $dcheck['snmp_community'] === '') {
						self::exception(ZBX_API_ERROR_PARAMETERS, _('Incorrect SNMP community.'));
					}
				case SVC_SNMPv3:
					if (!array_key_exists('key_', $dcheck) || $dcheck['key_'] === null || $dcheck['key_'] === false
							|| $dcheck['key_'] === '') {
						self::exception(ZBX_API_ERROR_PARAMETERS, _('Incorrect SNMP OID.'));
					}
					break;
			}

			// validate snmpv3 fields
			if (array_key_exists('snmpv3_securitylevel', $dcheck)
					&& $dcheck['snmpv3_securitylevel'] != ITEM_SNMPV3_SECURITYLEVEL_NOAUTHNOPRIV) {
				// snmpv3 authprotocol
				if ($dcheck['snmpv3_securitylevel'] == ITEM_SNMPV3_SECURITYLEVEL_AUTHNOPRIV
						|| $dcheck['snmpv3_securitylevel'] == ITEM_SNMPV3_SECURITYLEVEL_AUTHPRIV) {
					if (!array_key_exists('snmpv3_authprotocol', $dcheck)
							|| $dcheck['snmpv3_authprotocol'] != ITEM_AUTHPROTOCOL_MD5
								&& $dcheck['snmpv3_authprotocol'] != ITEM_AUTHPROTOCOL_SHA) {
						self::exception(ZBX_API_ERROR_PARAMETERS,
							_s('Incorrect value "%1$s" for "%2$s" field.',
								$dcheck['snmpv3_authprotocol'], 'snmpv3_authprotocol'
							)
						);
					}
				}

				// snmpv3 privprotocol
				if ($dcheck['snmpv3_securitylevel'] == ITEM_SNMPV3_SECURITYLEVEL_AUTHPRIV) {
					if (!array_key_exists('snmpv3_privprotocol', $dcheck)
							|| $dcheck['snmpv3_privprotocol'] != ITEM_PRIVPROTOCOL_DES
								&& $dcheck['snmpv3_privprotocol'] != ITEM_PRIVPROTOCOL_AES) {
						self::exception(ZBX_API_ERROR_PARAMETERS,
							_s('Incorrect value "%1$s" for "%2$s" field.',
								$dcheck['snmpv3_privprotocol'], 'snmpv3_privprotocol'
							)
						);
					}
				}
			}

			$this->validateDuplicateChecks($dchecks);
		}

		if ($uniq > 1) {
			self::exception(ZBX_API_ERROR_PARAMETERS, _('Only one check can be unique.'));
		}
	}

	protected function validateDuplicateChecks(array $dchecks) {
		$default_values = DB::getDefaults('dchecks');

		foreach ($dchecks as &$dcheck) {
			// set default values for snmpv3 fields
			if (!array_key_exists('snmpv3_securitylevel', $dcheck) || $dcheck['snmpv3_securitylevel'] === null) {
				$dcheck['snmpv3_securitylevel'] = ITEM_SNMPV3_SECURITYLEVEL_NOAUTHNOPRIV;
			}

			switch ($dcheck['snmpv3_securitylevel']) {
				case ITEM_SNMPV3_SECURITYLEVEL_NOAUTHNOPRIV:
					$dcheck['snmpv3_authprotocol'] = ITEM_AUTHPROTOCOL_MD5;
					$dcheck['snmpv3_privprotocol'] = ITEM_PRIVPROTOCOL_DES;
					$dcheck['snmpv3_authpassphrase'] = '';
					$dcheck['snmpv3_privpassphrase'] = '';
					break;
				case ITEM_SNMPV3_SECURITYLEVEL_AUTHNOPRIV:
					$dcheck['snmpv3_privprotocol'] = ITEM_PRIVPROTOCOL_DES;
					$dcheck['snmpv3_privpassphrase'] = '';
					break;
			}

			$dcheck += $default_values;
			unset($dcheck['uniq']);
		}
		unset($dcheck);

		while ($current = array_pop($dchecks)) {
			foreach ($dchecks as $dcheck) {
				$equal = true;
				foreach ($dcheck as $field => $value) {
					if (array_key_exists($field, $current) && (strcmp($value, $current[$field]) !== 0)) {
						$equal = false;
						break;
					}
				}
				if ($equal) {
					self::exception(ZBX_API_ERROR_PARAMETERS, _('Checks should be unique.'));
				}
			}
		}
	}

	/**
	 * Create new discovery rules.
	 *
	 * @param array(
	 *  name => string,
	 *  proxy_hostid => int,
	 *  iprange => string,
	 *  delay => string,
	 *  status => int,
	 *  dchecks => array(
	 *  	array(
	 *  		type => int,
	 *  		ports => string,
	 *  		key_ => string,
	 *  		snmp_community => string,
	 *  		snmpv3_securityname => string,
	 *  		snmpv3_securitylevel => int,
	 *  		snmpv3_authpassphrase => string,
	 *  		snmpv3_privpassphrase => string,
	 *  		uniq => int,
	 *  	), ...
	 *  )
	 * ) $drules
	 *
	 * @return array
	 */
	public function create(array $dRules) {
		$dRules = zbx_toArray($dRules);
		$this->validateCreate($dRules);

		$druleids = DB::insert('drules', $dRules);

		$dChecksCreate = [];
		foreach ($dRules as $dNum => $dRule) {
			foreach ($dRule['dchecks'] as $dCheck) {
				$dCheck['druleid'] = $druleids[$dNum];
				$dChecksCreate[] = $dCheck;
			}
		}

		DB::insert('dchecks', $dChecksCreate);

		return ['druleids' => $druleids];
	}

	/**
	 * Update existing drules.
	 *
	 * @param array(
	 * 	druleid => int,
	 *  name => string,
	 *  proxy_hostid => int,
	 *  iprange => string,
	 *  delay => string,
	 *  status => int,
	 *  dchecks => array(
	 *  	array(
	 * 			dcheckid => int,
	 *  		type => int,
	 *  		ports => string,
	 *  		key_ => string,
	 *  		snmp_community => string,
	 *  		snmpv3_securityname => string,
	 *  		snmpv3_securitylevel => int,
	 *  		snmpv3_authpassphrase => string,
	 *  		snmpv3_privpassphrase => string,
	 *  		uniq => int,
	 *  	), ...
	 *  )
	 * ) $dRules
	 *
	 * @return array
	 */
	public function update(array $dRules) {
		$dRules = zbx_toArray($dRules);
		$dRuleIds = zbx_objectValues($dRules, 'druleid');

		$dRulesDb = API::DRule()->get([
			'druleids' => $dRuleIds,
			'output' => API_OUTPUT_EXTEND,
			'selectDChecks' => API_OUTPUT_EXTEND,
			'editable' => true,
			'preservekeys' => true
		]);

		$this->validateUpdate($dRules, $dRulesDb);

		$defaultValues = DB::getDefaults('dchecks');

		$dRulesUpdate = [];

		foreach ($dRules as $dRule) {
			$dRulesUpdate[] = [
				'values' => $dRule,
				'where' => ['druleid' => $dRule['druleid']]
			];

			// update dchecks
			$dbChecks = $dRulesDb[$dRule['druleid']]['dchecks'];

			$newChecks = [];
			$oldChecks = [];

			foreach ($dRule['dchecks'] as $check) {
				$check['druleid'] = $dRule['druleid'];

				if (!isset($check['dcheckid'])) {
					$newChecks[] = array_merge($defaultValues, $check);
				}
				else {
					$oldChecks[] = $check;
				}
			}

			$delDCheckIds = array_diff(
				zbx_objectValues($dbChecks, 'dcheckid'),
				zbx_objectValues($oldChecks, 'dcheckid')
			);

			if ($delDCheckIds) {
				$this->deleteActionConditions($delDCheckIds);
			}

			DB::replace('dchecks', $dbChecks, array_merge($oldChecks, $newChecks));
		}

		DB::update('drules', $dRulesUpdate);

		return ['druleids' => $dRuleIds];
	}

	/**
	 * Delete drules.
	 *
	 * @param array $dRuleIds
	 *
	 * @return array
	 */
	public function delete(array $dRuleIds) {
		$this->validateDelete($dRuleIds);

		$actionIds = [];
		$conditionIds = [];

		$dCheckIds = [];

		$dbChecks = DBselect('SELECT dc.dcheckid FROM dchecks dc WHERE '.dbConditionInt('dc.druleid', $dRuleIds));

		while ($dbCheck = DBfetch($dbChecks)) {
			$dCheckIds[] = $dbCheck['dcheckid'];
		}

		$dbConditions = DBselect(
			'SELECT c.conditionid,c.actionid'.
			' FROM conditions c'.
			' WHERE (c.conditiontype='.CONDITION_TYPE_DRULE.' AND '.dbConditionString('c.value', $dRuleIds).')'.
				' OR (c.conditiontype='.CONDITION_TYPE_DCHECK.' AND '.dbConditionString('c.value', $dCheckIds).')'
		);

		while ($dbCondition = DBfetch($dbConditions)) {
			$conditionIds[] = $dbCondition['conditionid'];
			$actionIds[] = $dbCondition['actionid'];
		}

		if ($actionIds) {
			DB::update('actions', [
				'values' => ['status' => ACTION_STATUS_DISABLED],
				'where' => ['actionid' => array_unique($actionIds)]
			]);
		}

		if ($conditionIds) {
			DB::delete('conditions', ['conditionid' => $conditionIds]);
		}

		$result = DB::delete('drules', ['druleid' => $dRuleIds]);
		if ($result) {
			foreach ($dRuleIds as $dRuleId) {
				add_audit(AUDIT_ACTION_DELETE, AUDIT_RESOURCE_DISCOVERY_RULE, '['.$dRuleId.']');
			}
		}

		return ['druleids' => $dRuleIds];
	}

	/**
	 * Validates the input parameters for the delete() method.
	 *
	 * @throws APIException if the input is invalid
	 *
	 * @param array $druleIds
	 *
	 * @return void
	 */
	protected function validateDelete(array $druleIds) {
		if (!$druleIds) {
			self::exception(ZBX_API_ERROR_PARAMETERS, _('Empty input parameter.'));
		}

		$this->checkDrulePermissions($druleIds);
	}

	/**
	 * Delete related action conditions.
	 *
	 * @param array $dCheckIds
	 */
	protected function deleteActionConditions(array $dCheckIds) {
		$actionIds = [];

		// conditions
		$dbActions = DBselect(
			'SELECT DISTINCT c.actionid'.
			' FROM conditions c'.
			' WHERE c.conditiontype='.CONDITION_TYPE_DCHECK.
				' AND '.dbConditionString('c.value', $dCheckIds).
			' ORDER BY c.actionid'
		);
		while ($dbAction = DBfetch($dbActions)) {
			$actionIds[] = $dbAction['actionid'];
		}

		// disabling actions with deleted conditions
		if ($actionIds) {
			DB::update('actions', [
				'values' => ['status' => ACTION_STATUS_DISABLED],
				'where' => ['actionid' => $actionIds],
			]);

			DB::delete('conditions', [
				'conditiontype' => CONDITION_TYPE_DCHECK,
				'value' => $dCheckIds
			]);
		}
	}

	/**
	 * Check if user has read permissions for discovery rule.
	 *
	 * @param array $ids
	 *
	 * @return bool
	 */
	public function isReadable(array $ids) {
		if (empty($ids)) {
			return true;
		}

		$ids = array_unique($ids);

		$count = $this->get([
			'druleids' => $ids,
			'countOutput' => true
		]);

		return (count($ids) == $count);
	}

	/**
	 * Check if user has write permissions for discovery rule.
	 *
	 * @param array $ids
	 *
	 * @return bool
	 */
	public function isWritable(array $ids) {
		if (empty($ids)) {
			return true;
		}

		$ids = array_unique($ids);

		$count = $this->get([
			'druleids' => $ids,
			'editable' => true,
			'countOutput' => true
		]);

		return (count($ids) == $count);
	}

	protected function addRelatedObjects(array $options, array $result) {
		$result = parent::addRelatedObjects($options, $result);

		$druleids = array_keys($result);

		// Adding Discovery Checks
		if (!is_null($options['selectDChecks'])) {
			if ($options['selectDChecks'] != API_OUTPUT_COUNT) {
				$relationMap = $this->createRelationMap($result, 'druleid', 'dcheckid', 'dchecks');
				$dchecks = API::DCheck()->get([
					'output' => $options['selectDChecks'],
					'dcheckids' => $relationMap->getRelatedIds(),
					'nopermissions' => true,
					'preservekeys' => true
				]);
				if (!is_null($options['limitSelects'])) {
					order_result($dchecks, 'dcheckid');
				}
				$result = $relationMap->mapMany($result, $dchecks, 'dchecks', $options['limitSelects']);
			}
			else {
				$dchecks = API::DCheck()->get([
					'druleids' => $druleids,
					'nopermissions' => true,
					'countOutput' => true,
					'groupCount' => true
				]);
				$dchecks = zbx_toHash($dchecks, 'druleid');
				foreach ($result as $druleid => $drule) {
					if (isset($dchecks[$druleid]))
						$result[$druleid]['dchecks'] = $dchecks[$druleid]['rowscount'];
					else
						$result[$druleid]['dchecks'] = 0;
				}
			}
		}

		// Adding Discovery Hosts
		if (!is_null($options['selectDHosts'])) {
			if ($options['selectDHosts'] != API_OUTPUT_COUNT) {
				$relationMap = $this->createRelationMap($result, 'druleid', 'dhostid', 'dhosts');
				$dhosts = API::DHost()->get([
					'output' => $options['selectDHosts'],
					'dhostids' => $relationMap->getRelatedIds(),
					'preservekeys' => true
				]);
				if (!is_null($options['limitSelects'])) {
					order_result($dhosts, 'dhostid');
				}
				$result = $relationMap->mapMany($result, $dhosts, 'dhosts', $options['limitSelects']);
			}
			else {
				$dhosts = API::DHost()->get([
					'druleids' => $druleids,
					'countOutput' => true,
					'groupCount' => true
				]);
				$dhosts = zbx_toHash($dhosts, 'druleid');
				foreach ($result as $druleid => $drule) {
					if (isset($dhosts[$druleid]))
						$result[$druleid]['dhosts'] = $dhosts[$druleid]['rowscount'];
					else
						$result[$druleid]['dhosts'] = 0;
				}
			}
		}

		return $result;
	}

	/**
	 * Checks if the current user has access to given discovery rules.
	 *
	 * @throws APIException if the user doesn't have write permissions for discovery rules.
	 *
	 * @param array $druleIds
	 *
	 * @return void
	 */
	protected function checkDrulePermissions(array $druleIds) {
		if (!$this->isWritable($druleIds)) {
			self::exception(ZBX_API_ERROR_PERMISSIONS, _('No permissions to referred object or it does not exist!'));
		}
	}
}
