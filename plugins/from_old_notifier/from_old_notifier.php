<?php

class from_old_notifier extends rcube_plugin
{
    function init()
    {
        $rcmail = rcmail::get_instance();

        $this->include_script('from_old_notifier.js');
        $this->include_stylesheet('from_old_notifier.css');
    }
}
