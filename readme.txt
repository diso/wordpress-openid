=== WP-OpenID+ ===
Contributors: wnorris
Tags: openid
Requires at least: 2.0
Tested up to: 2.1.2
Stable tag: 1.0.1

Allow the use of OpenID for authentication of users and commenters.


== Description ==

OpenID is an [open standard][] that lets you sign in to other sites on the Web
using little more than your blog URL. This means less usernames and passwords
to remember and less time spent signing up for new sites.  This plugin allows
verified OpenIDs to be linked to existing user accounts for use as an
alternative means of authentication.  Additionally, commenters may use their
OpenID to assure their identity as the author of the comment and provide a
framework for future OpenID-based services (reputation and trust, for
example).

This plugin was started as a fork of Alan Castonguay's [wpopenid][] plugin, and
has since added a number of significant features and bug-fixes to the excellent
foundation Alan provided.  Special thanks to [Mike Giarlo][] for donating the
`openid` project space.

[open standard]: http://openid.net/
[wpopenid]: http://verselogic.net/projects/wordpress/wordpress-openid-plugin/
[Mike Giarlo]: http://www.lackoftalent.org/michael/blog/


== Installation ==

This plugin follows the [standard WordPress installation method][]:

1. Upload the `wpopenid` folder to the `/wp-content/plugins/` directory
1. Activate the plugin through the 'Plugins' menu in WordPress
1. Configure the plugin through the 'OpenID' section of the 'Options' menu

[standard WordPress installation method]: http://codex.wordpress.org/Managing_Plugins#Installing_Plugins


== Frequently Asked Questions ==

= How do I get help if I have a problem? =

Please direct support questions to the "Plugins and Hacks" section of the
[WordPress.org Support Forum][].  Just make sure and include the tag 'openid'
or 'wpopenid', so that I'll see your post.  Additionally, you can file a bug
report at <http://dev.wp-plugins.org/report>.  Existing bugs and feature
requests can also be found at [wp-plugins.org][bugs-reports].

[WordPress.org Support Forum]: http://wordpress.org/support/
[bugs-reports]: http://dev.wp-plugins.org/report/9?COMPONENT=openid


== Changelog ==

= (unreleased) =
a full list of subversion commits that have been made since the last release can be found [here][changelog-unreleased]
 
= version 1.0 (also known as r13) =
a full list of svn commit messages can be found [here][changelog-1.0]

[changelog-unreleased]: http://dev.wp-plugins.org/log/openid/trunk/?stop_rev=11273
[changelog-1.0]: http://dev.wp-plugins.org/log/openid/trunk/?rev=11272&stop_rev=11260
