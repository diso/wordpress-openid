jQuery(document).ready( function() {
	jQuery('#openid_rollup dl').toggle();

	jQuery('#openid_rollup_link').click( function() {
		return false;
	});
});

function stylize_profilelink() {
	jQuery('#commentform a[@href$=/wp-admin/profile.php]').addClass('openid_link');;
}

function add_openid_to_comment_form(unobtrusive_mode) {

	jQuery('#commentform').addClass('openid');

	if (unobtrusive_mode == true) {
		var unobtrusive_html = ' <a id="openid_enabled_link" href="http://openid.net">(OpenID Enabled)</a> ' +
					'<div id="openid_unobtrusive_text">' +
						'If you have an OpenID, you may fill it in here.  If your OpenID provider provides ' + 
						'a name and email, those values will be used instead of the values here.  ' + 
						'<a href="http://openid.net">Learn more about OpenID</a> or ' + 
						'<a href="http://openid.net/wiki/index.php/Public_OpenID_providers">find an OpenID provider</a>.' +
					'</div> ';

		var children = jQuery(':visible:hastext', label);

		if (children.length > 0)
			children.filter(':last').appendToText(unobtrusive_html);
		else if (label.is(':hastext'))
			label.appendToText(unobtrusive_html);
		else
			label.append(unobtrusive_html);

		// setup action
		jQuery('#openid_unobtrusive_text').hide();
		jQuery('#openid_enabled_link').click( function() {
			jQuery('#openid_unobtrusive_text').toggle(200); 
			return false;
		});
	} else {
		// Create fieldsets
		jQuery('#commentform label:first/..')
			.before('<fieldset id="openid_fieldset"><legend>OpenID</legend></fieldset>')
			.before('<fieldset id="anonymous_fieldset"><legend>Anonymous</legend></fieldset>')

		var fields = new Array('author', 'email', 'url');
		var label,input,index,lowest;

		// move each field into the anonymous fieldset.  Also clone the URL field and add it to the openid fieldset.
		for (i=0; i<fields.length; i++) {
			label = jQuery('#commentform label[@for='+fields[i]+']/..');
			input = jQuery('#commentform input#'+fields[i]+'/..');

			index = input.children('input').attr('tabindex');
			if (!lowest || index<lowest) lowest=index;

			jQuery('#anonymous_fieldset').append(label);
			if (input[0] != label[0]) jQuery('#anonymous_fieldset').append(input);

			if (fields[i] == 'url') {
				label.clone().appendTo(jQuery('#openid_fieldset'));
				if (input[0] != label[0]) input.clone().appendTo(jQuery('#openid_fieldset'));
			}
		}

		// fix up our new openid input field
		var openid_text = 'Sign in with your <a title="What is this?" href="http://www.openid.net">OpenID</a>';

		jQuery('#openid_fieldset input').attr('id','openid_url')
			.attr('name','openid_url').attr('tabindex', (lowest-1));

		var label = jQuery('#openid_fieldset label').attr('for', 'openid_url');;
		var children = jQuery(':visible:hastext', label);

		if (children.length > 0)
			children.filter(':last').html(openid_text);
		else if (label.is(':hastext'))
			label.replaceText(openid_text);
		else
			label.append(openid_text);

		// fix tabindex values
		jQuery('#commentform input[@tabindex]')
			.each( function() { jQuery(this).attr('tabindex', parseInt(jQuery(this).attr('tabindex')) + 1); });
	}
}
