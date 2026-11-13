#!/bin/bash
set -euo pipefail
cd /home/vianaedias/public_html/includes/vd_workflow_deployment
install -d -m 0755 /usr/local/libexec
install -o root -g root -m 0644 quote-ink-worker.php /usr/local/libexec/vd-ink-worker.php
install -o root -g root -m 0755 vd-ink-analyze /usr/local/sbin/vd-ink-analyze
printf '%s\n' 'vianaedias ALL=(root) NOPASSWD: /usr/local/sbin/vd-ink-analyze ""' > /etc/sudoers.d/vd-ink
chmod 0440 /etc/sudoers.d/vd-ink
/usr/sbin/visudo -cf /etc/sudoers.d/vd-ink
