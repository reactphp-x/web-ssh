<?php

declare(strict_types=1);

return [
    'deny_binaries' => [
        'vim', 'vi', 'nvim', 'nano', 'emacs', 'top', 'htop', 'btop', 'less', 'more', 'man',
        'reboot', 'shutdown', 'poweroff', 'halt', 'init', 'mkfs', 'fdisk', 'parted', 'dd', 'telnet',
        'ftp', 'sftp', 'scp', 'nc', 'ncat', 'nmap',
    ],
    'require_approval_binaries' => [
        'systemctl', 'service', 'kill', 'pkill', 'killall', 'useradd', 'userdel', 'usermod',
        'groupadd', 'groupdel', 'chmod', 'chown', 'chgrp', 'mv', 'cp', 'rm', 'mkdir', 'rmdir',
        'touch', 'ln', 'tee', 'crontab', 'at', 'iptables', 'ufw', 'firewall-cmd', 'mount',
        'umount', 'kubectl',
        'mysql', 'mariadb', 'psql', 'redis-cli', 'mongo', 'mongosh', 'sqlite3',
    ],
];
