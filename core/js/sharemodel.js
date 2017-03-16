/*
 * Copyright (c) 2017
 *
 * This file is licensed under the Affero General Public License version 3
 * or later.
 *
 * See the COPYING-README file.
 *
 */

(function() {
	/**
	 * @class OC.Share.ShareModel
	 * @classdesc
	 *
	 * Represents a single share.
	 */
	var ShareModel = OC.Backbone.Model.extend({
		url: function() {
			var params = {
				format: 'json'
			};
			var idPart = '';
			// note: unsaved new models have no ids at first but need a POST URL
			if (!_.isUndefined(this.id)) {
				idPart = '/' + encodeURIComponent(this.id);
			}
			return OC.linkToOCS('apps/files_sharing/api/v1', 2) +
				'shares' +
				idPart +
				'?' + OC.buildQueryString(params);
		},

		parse: function(data) {
			/* jshint camelcase: false */
			if (data.ocs && data.ocs.data) {
				// parse out of the ocs response
				data = data.ocs.data;
			}

			// parse the non-camel to camel
			if (data.share_type === OC.Share.SHARE_TYPE_LINK) {
				data.password = data.share_with;
				delete data.share_with;
			}

			data.itemSource = data.item_source;
			delete data.item_source;
			data.itemType = data.item_type;
			delete data.item_type;

			// convert the inconsistency... (read as expiration, saved as expireDate...)
			if (data.expiration && !data.expireDate) {
				data.expireDate = data.expiration;
				delete data.expiration;
			}
			if (_.isUndefined(data.expireDate)) {
				data.expireDate = null;
			}
			return data;
		},

		canCreate: function() {
			return (this.get('permissions') & OC.PERMISSION_CREATE) > 0;
		},

		/**
		 * Returns the absolute link share
		 *
		 * @return {String} link
		 */
		getLink: function() {
			var url = this.get('url');
			if (url) {
				return url;
			}

			return OC.webroot + OC.generateUrl('/s/') + this.get('token');
		}
	});

	OC.Share = OC.Share || {};
	OC.Share.ShareModel = ShareModel;
})();
