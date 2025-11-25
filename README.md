<h3 align="center">MySupport</h3>

<div align="center">

[![Status](https://img.shields.io/badge/status-active-success.svg)]()
[![GitHub Issues](https://img.shields.io/github/issues/OUGC-Network/MySupport.svg)](./issues)
[![GitHub Pull Requests](https://img.shields.io/github/issues-pr/OUGC-Network/MySupport.svg)](./pulls)
[![License](https://img.shields.io/badge/license-GPL-blue)](/LICENSE)

</div>

---

<p align="center"> Add features to your forum to help with giving support.
    <br> 
</p>

## 📜 Table of Contents <a name = "table_of_contents"></a>

- [About](#about)
- [Getting Started](#getting_started)
    - [Dependencies](#dependencies)
    - [File Structure](#file_structure)
    - [Install](#install)
    - [Update](#update)
    - [Template Modifications](#template_modifications)
- [Settings](#settings)
    - [File Level Settings](#file_level_settings)
- [Usage](#usage)
    - [Categories](#usage_categories)
        - [MyShowcase](#usage_categories_myshowcase)
- [Templates](#templates)
- [Built Using](#built_using)
- [Authors](#authors)
- [Acknowledgments](#acknowledgement)
- [Support & Feedback](#support)

## 🚀 About <a name = "about"></a>

Allows you to mark a thread as solved or technical, assign threads to users, give threads priorities, mark a post as the
best answer in a thread, and more to help you run a support forum.

[Go up to Table of Contents](#table_of_contents)

## 📍 Getting Started <a name = "getting_started"></a>

The following information will assist you into getting a copy of this plugin up and running on your forum.

### Dependencies <a name = "dependencies"></a>

A setup that meets the following requirements is necessary to use this plugin.

- [MyBB](https://mybb.com/) >= 1.8
- [MyShowcase](https://github.com/Sama34/MyShowcase-System) >= 3.0 (Optional)
- PHP >= 8
- [MyBB-PluginLibrary](https://github.com/frostschutz/MyBB-PluginLibrary) >= 13

### File structure <a name = "file_structure"></a>

  ```
   .
   ├── inc
   │ ├── plugins
   │ ├── mysupport
   ```

### Installing <a name = "install"></a>

Follow the next steps in order to install a copy of this plugin on your forum.

1. Download the latest package from the [MyBB Extend](https://community.mybb.com/mods.php) site or
   from the [repository releases](https://github.com/OUGC-Network/MySupport/releases/latest).
2. Upload the contents of the _Upload_ folder to your MyBB root directory.
3. Browse to _Configuration » Plugins_ and install this plugin by clicking _Install & Activate_.
4. Browse to _Settings_ to manage the plugin settings.

### Updating <a name = "update"></a>

Follow the next steps in order to update your copy of this plugin.

1. Browse to _Configuration » Plugins_ and deactivate this plugin by clicking _Deactivate_.
2. Follow step 1 and 2 from the [Install](#install) section.
3. Browse to _Configuration » Plugins_ and activate this plugin by clicking _Activate_.
4. Browse to _Settings_ to manage the plugin settings.

### Template Modifications <a name = "template_modifications"></a>

To display MySupport data, it is required that you edit the following templates for each of your themes.

1. Place `<!--MySupportInlineModerationOptions-->` after `{$customthreadtools}` in the `forumdisplay_inlinemoderation`
   template.
2. Place `<!--MySupportInlineModerationOptions-->` after `{$customthreadtools}` in the `showthread_moderationoptions`
   template.
3. Place `<!--MySupportInlineModerationOptions-->` after `{$customthreadtools}` in the
   `search_results_threads_inlinemoderation` template.
4. Place `{$post['mySupportQuickButtons']}` before `{$post['button_edit']}` in the `postbit` and `postbit_classic`
   template to display the thread quick buttons.
5. Place `<!--MySupportPriorityClass-->` after `{$bgcolor}` in the `search_results_threads_inlinecheck` template.
6. Place `{$post['mySupportPriorityClass']}` after `{$unapproved_shade}` in the `postbit` and `postbit_classic`
   template.
7. Place `{$post['mySupportBestAnswerHighlight']}{$post['mySupportStaffHighlight']}` after `post_content` in the
   `postbit` and `postbit_classic` template.
8. Place `{$post['mySupportDenySupport']}{$post['mySupportBestAnswer']}` after `{$post['subject_extra']}` in the
   `postbit` and `postbit_classic` template.
9. Place `<!--MySupportQuickReplyModerationNotice-->` after `{$moderation_notice}` in the `showthread_quickreply`
   template.
10. Place `{$mySupportGlobalNoticeTechnical}` after `{$awaitingusers}` in the `header` template.
11. Place `{$mySupportGlobalNoticeAssigned}` after `{$awaitingusers}` in the `header` template.
12. Place `{$mySupportProfileDetails}` after `{$profilefields}` in the `member_profile` template.
13. Place `{$mySupportModeratorNavigationItems}` after `{$modcp_nav_users}` in the `modcp_nav` template.
14. Place `<!--MySupportUserPanelNavigation-->` after `{$attachmentop}` in the `usercp_nav_misc` template.
15. Place `{$thread['mySupportBestAnswerButton']}` before `{$newreply}` in the `showthread` template.
16. Place
    `{$thread['mySupportStatus']}{$thread['mySupportAssignedNotice']}{$thread['mySupportBestAnswerNotice']}{$thread['mySupportCategoryFormatted']}`
    before `{$thread['displayprefix']}` in the `showthread` template.
17. Place
    `{$thread['mySupportStatus']}{$thread['mySupportAssignedNotice']}{$thread['mySupportBestAnswerNotice']}{$thread['mySupportCategoryFormatted']}`
    before `{$thread['displayprefix']}` in the  `forumdisplay_thread` template.
18. Place `{$thread['mySupportPriorityClass']}` after `{$bgcolor}` in the `forumdisplay_thread` template.
19. Place `{$thread['mySupportPriorityClass']}` after `{$bgcolor}` in the `forumdisplay_thread_rating` template.
20. Place `{$thread['mySupportPriorityClass']}` after `{$bgcolor}` in the `forumdisplay_thread_modbit` template.
21. Place
    `{$thread['mySupportStatus']}{$thread['mySupportAssignedNotice']}{$thread['mySupportBestAnswerNotice']}{$thread['mySupportCategoryFormatted']}`
    before `{$thread['threadprefix']}` in the `search_results_threads_thread`
    template.
22. Place `{$thread['mySupportPriorityClass']}` after `{$bgcolor}` in the `search_results_threads_thread` template.
23. Place `<!--MySupportPriorityClass-->` after `{$bgcolor}` in the `search_results_threads_inlinecheck` template.
24. Place `<!--MySupportPriorityClass-->` after `{$bgcolor}` in the `search_results_threads_nocheck` template.
25. Place
    `{$thread['mySupportStatus']}{$thread['mySupportAssignedNotice']}{$thread['mySupportBestAnswerNotice']}{$thread['mySupportCategoryFormatted']}`
    before `{$thread['displayprefix']}` in the  `usercp_latest_subscribed_threads` template.
26. Place `{$thread['mySupportPriorityClass']}` after `{$bgcolor}` in the `usercp_latest_subscribed_threads` template.
27. Place
    `{$thread['mySupportStatus']}{$thread['mySupportAssignedNotice']}{$thread['mySupportBestAnswerNotice']}{$thread['mySupportCategoryFormatted']}`
    before `{$thread['displayprefix']}` in the  `usercp_latest_threads_threads` template.
28. Place `{$thread['mySupportPriorityClass']}` after `{$bgcolor}` in the `usercp_latest_threads_threads` template.
29. Place
    `{$thread['mySupportStatus']}{$thread['mySupportAssignedNotice']}{$thread['mySupportBestAnswerNotice']}{$thread['mySupportCategoryFormatted']}`
    before `{$thread['threadprefix']}` in the  `usercp_subscriptions_thread` template.
30. Place `{$thread['mySupportPriorityClass']}` after `{$bgcolor}` in the `usercp_subscriptions_thread` template.
31. Place
    `{$thread['mySupportStatus']}{$thread['mySupportAssignedNotice']}{$thread['mySupportBestAnswerNotice']}{$thread['mySupportCategoryFormatted']}`
    before `{$thread['displaystyle']}` in the  `printthread` template.

[Go up to Table of Contents](#table_of_contents)

## 🛠 Settings <a name = "settings"></a>

Below you can find a description of the plugin settings.

### Main Settings

### File Level Settings <a name = "file_level_settings"></a>

Additionally, you can force your settings by updating the `SETTINGS` array constant in the `MySupport\Core`
namespace in the `./inc/plugins/mysupport.php` file. Any setting set this way will always bypass any front-end
configuration. Use the setting key as shown below:

```PHP
define('MySupport\Core\SETTINGS', [
]);
```

[Go up to Table of Contents](#table_of_contents)

## 📖 Usage <a name="usage"></a>

This plugin has no additional configurations; after activating, make sure to modify the global settings to get this
plugin working.

[Go up to Table of Contents](#table_of_contents)

## 📐 Templates <a name = "templates"></a>

The following is a list of templates available for this plugin.

[Go up to Table of Contents](#table_of_contents)

## ⛏ Built Using <a name = "built_using"></a>

- [MyBB](https://mybb.com/) - Web Framework
- [MyBB PluginLibrary](https://github.com/frostschutz/MyBB-PluginLibrary) - A collection of useful functions for MyBB
- [PHP](https://www.php.net/) - Server Environment

[Go up to Table of Contents](#table_of_contents)

## ✍️ Authors <a name = "authors"></a>

- [@Omar G](https://github.com/Sama34) - Idea & Initial work

See also the list of [contributors](https://github.com/OUGC-Network/MySupport/contributors) who participated in
this
project.

[Go up to Table of Contents](#table_of_contents)

## 🎉 Acknowledgements <a name = "acknowledgement"></a>

- [The Documentation Compendium](https://github.com/kylelobo/The-Documentation-Compendium)

[Go up to Table of Contents](#table_of_contents)

## 🎈 Support & Feedback <a name="support"></a>

This is free development and any contribution is welcome. Get support or leave feedback at the
official [MyBB Community](https://community.mybb.com/thread-159249.html).

Thanks for downloading and using our plugins!

[Go up to Table of Contents](#table_of_contents)