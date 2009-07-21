/* yuicompress openid.js -o openid.min.js
 * @see http://developer.yahoo.com/yui/compressor/
 */

jQuery(function() {
	jQuery('#openid_system_status').hide();

	jQuery('#openid_status_link').click( function() {
		jQuery('#openid_system_status').toggle();
		return false;
	});
});

function stylize_profilelink() {
	jQuery("#commentform a[href$='profile.php']").addClass('openid_link');
}

function add_openid_to_comment_form(wp_url, nonce) {
	var openid_nonce = nonce;

	openid_comment = jQuery('#openid_comment');
	openid_checkbox = jQuery('#login_with_openid');
	url = jQuery('#url');

	openid_comment.insertAfter(url).hide();

	if ( url.val() ) check_openid( url );
	url.blur( function() { check_openid(jQuery(this)); } );

	function check_openid( url ) {
		jQuery.getJSON(wp_url + '/openid/ajax', {url: url.val(), _wpnonce: openid_nonce}, function(data, textStatus) {
			if ( data.valid ) {
				openid_checkbox.attr('checked', 'checked');
				openid_comment.slideDown();
			} else {
				openid_checkbox.attr('checked', '');
				openid_comment.slideUp();
			}
			openid_nonce = data.nonce;
		});
	}
}

