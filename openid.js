jQuery(document).ready( function() {
	jQuery('#openid_unobtrusive_text').hide();

	jQuery('#openid_enabled_link').click( function() {
		jQuery('#openid_unobtrusive_text').toggle(400); 
		return false;
	});
});
