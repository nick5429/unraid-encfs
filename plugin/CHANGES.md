### 2026.10.19b
- Fix: volumes whose mount point is inside an exclusive share (where /mnt/user/<share> is a symlink to the pool) mounted correctly but were reported as failed / "Not mounted". Mount detection now resolves symlinked parent directories.
- Clearer message if encfs reports success but no mount appears

### 2026.10.19a
- Fix: Save/Mount could hang on "Saving..." - the page now posts URL-encoded requests like the rest of the webGUI (multipart bodies stall before reaching PHP on Unraid 7.3.2)
- Requests now time out with an error message instead of waiting indefinitely
- Status checks no longer stat a path that is already mounted

### 2026.10.19
- Complete rewrite for Unraid 7
- Bundles a statically linked encfs 1.9.5 (no boost/rlog/tcl/expect packages needed)
- Multiple volumes, managed from Settings > User Utilities > EncFS
- Password goes straight to encfs over a pipe: never on a command line, in a file or in a log; wrong passwords are reported in the dialog
- Optional auto-mount at array start from a password file
- Volumes are unmounted when the array stops, with escalation so a busy volume cannot hang the array stop
- Detects stale mounts ("Transport endpoint is not connected") and offers a forced cleanup
- Initialize new volumes (standard or paranoia) from the webGUI
- Backs up each volume's .encfs6.xml to the flash drive
- Optional lock (mode 000) on the empty mount point so nothing is written unencrypted while unmounted
- encfs-mgr command line tool
- Imports the volume from the 2016 plugin configuration
