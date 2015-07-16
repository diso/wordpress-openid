jQuery(document).ready(function() {
	jQuery(function() {
		jQuery("#registerform > p:first").hide();
		jQuery("#registerform > p:first + p").hide();
		jQuery("#reg_passmail").hide();
		jQuery("p.submit").css("margin", "1em 0");
		var link = jQuery("#nav a:first");
		jQuery("#nav").text("").append(link);
	});
});
