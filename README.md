# unraid-encfs

2012-10-03 0.1-beta      Put together an initial version.

DISCLAIMER: This seems to work for me on unraid 5.0-rc6-r8168-test2. I offer this plugin as a convenience and offer no warranty or guarantee.  If you lose all your encrypted data, or if through some failing the data is actually not encrypted after all, or your password gets leaked, I'm sorry, but I claim no responsibility.

To Do / Planned:
   * If someone wants to make a prettier icon for this, that would be swell! Just send it to me and I'll include it :)
   * Add configurable 'unmount after X idle minutes' option (pass as an option to the encfs mount command)
   * Add support for multiple encrypted mounts
      - Maybe. Don't hold your breath. I only use one encfs volume right now and so far this works fine for me as-is. Feel free to add it and re-contribute the source :)
   
Unlikely:
   * Support for creating the encrypted mountpoint from within the plugin.  Too much to go wrong.  Just follow the instructions on the plugin's page to do it on the command line.
   
Notes:
   * I haven't tested moving the "install directory"
   * It seems like a good idea to set up your backing store to be on a specific disk, rather than on a user share. 
     encFs has one magic metadata file stored in the backing store that's required to decode any and all data (in addition to your password)
     If you set it up on a user share, make sure you back this up separately if you want a chance of recovering your data in a multi-disk failure.
   * If you ever access the decrypted mountpoint via a user share rather than from a disk# share, it seems like a good idea set up unraid to ONLY write that usershare's data to that specific disk.  Otherwise, it could potentially write outside the encrypted container when copying stuff over.
   
Known issues:
   * Sometimes the mounting is flaky through the 'expect' script used to mount from the web interface.  I've never had this happen when calling encfs directly from the command line, even after it happens through the web interface.
      It appears to be executing the same command, but doing an "ls $mountpoint" will come back with "Transport endpoint is not connected"
     Unmounting, deleting the mountpoint directory, and letting encfs re-create it fixed the problem for me sometimes.
     I can't figure out what causes this, and it's really annoying.  If it happens to you and you can figure it out (and even better, how to reliably fix/work around it), that would be swell.
    
   * Sometimes after mounting through the webpage interface, the page remains loading indefinitely after displaying that it's trying to mount. Maybe the expect script isn't returning?
     It's got a 10 second timeout though, so that shouldn't be the issue...
     Mounting seems to always work fine when this happens, just click another link in the UI and come back if you want.
