/*
** Zabbix
** Copyright (C) 2001-2019 Zabbix SIA
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

package com.zabbix.gateway;

import java.util.ArrayList;

import org.json.*;

import org.slf4j.Logger;
import org.slf4j.LoggerFactory;

abstract class ItemChecker
{
	private static final Logger logger = LoggerFactory.getLogger(ItemChecker.class);

	static final String JSON_TAG_DATA = "data";
	static final String JSON_TAG_ERROR = "error";
	static final String JSON_TAG_KEYS = "keys";
	static final String JSON_TAG_PASSWORD = "password";
	static final String JSON_TAG_REQUEST = "request";
	static final String JSON_TAG_RESPONSE = "response";
	static final String JSON_TAG_USERNAME = "username";
	static final String JSON_TAG_VALUE = "value";
	static final String JSON_TAG_JMX_ENDPOINT = "jmx_endpoint";

	static final String JSON_REQUEST_INTERNAL = "java gateway internal";
	static final String JSON_REQUEST_JMX = "java gateway jmx";

	static final String JSON_RESPONSE_FAILED = "failed";
	static final String JSON_RESPONSE_SUCCESS = "success";

	protected JSONObject request;
	protected ArrayList<String> keys;

	protected ItemChecker(JSONObject request) throws ZabbixException
	{
		this.request = request;

		try
		{
			JSONArray jsonKeys = request.getJSONArray(JSON_TAG_KEYS);
			keys = new ArrayList<String>();

			for (int i = 0; i < jsonKeys.length(); i++)
				keys.add(jsonKeys.getString(i));
		}
		catch (Exception e)
		{
			throw new ZabbixException(e);
		}
	}

	String getFirstKey()
	{
		return 0 == keys.size() ? null : keys.get(0);
	}

	protected abstract JSONArray getValues() throws ZabbixException;
}
