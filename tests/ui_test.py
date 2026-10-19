#!/usr/bin/env python3
"""Browser test for the EncFS page, run against tests/harness/router.php (see README).
Needs playwright with chromium. Creates and force-unmounts a "Photos" volume under /mnt/user."""
import asyncio, sys
from playwright.async_api import async_playwright

import os
BASE = os.environ.get("ENCFS_UI_BASE", "http://127.0.0.1:8080") + "/Settings/EncFS"
OUT = os.environ.get("ENCFS_UI_SHOTS", "/tmp/encfs-ui-shots")

async def main():
    os.makedirs(OUT, exist_ok=True)
    async with async_playwright() as p:
        b = await p.chromium.launch()
        page = await b.new_page(viewport={"width": 1280, "height": 800})
        errors = []
        page.on("console", lambda m: errors.append(f"{m.type}: {m.text}") if m.type in ("error", "warning") else None)
        page.on("pageerror", lambda e: errors.append(f"pageerror: {e}"))
        await page.goto(BASE + "?theme=black")
        await page.wait_for_selector("#encfs-rows tr td.encfs-actions")
        await page.screenshot(path=f"{OUT}/01-list-black.png", full_page=True)

        # Mount: wrong password, then right password
        await page.click("input[value=Mount]")
        await page.fill("#encfs-mount-form input[name=password]", "wrong")
        await page.screenshot(path=f"{OUT}/02-mount-dialog.png")
        await page.click("#encfs-mount-form input[data-role=submit]")
        await page.wait_for_selector("#encfs-mount-dialog [data-role=error]:not([hidden])")
        print("wrong pw error:", await page.inner_text("#encfs-mount-dialog [data-role=error]"))
        await page.screenshot(path=f"{OUT}/03-mount-wrong.png")
        await page.fill("#encfs-mount-form input[name=password]", "correct horse")
        await page.click("#encfs-mount-form input[data-role=submit]")
        await page.wait_for_selector("text=Mounted")
        await page.screenshot(path=f"{OUT}/04-mounted.png", full_page=True)

        # Info
        await page.click("input[value=Info]")
        await page.wait_for_selector("#encfs-info-dialog pre:has-text('Filesystem cipher')")
        await page.screenshot(path=f"{OUT}/05-info.png")
        await page.click("#encfs-info-dialog input[value=Close]")

        # Unmount
        await page.click("input[value=Unmount]")
        await page.wait_for_selector("input[value=Mount]")

        # Add a new volume (empty dir) and initialize it
        await page.click("#encfs-add")
        await page.fill("#encfs-edit-form input[name=volname]", "Photos")
        await page.fill("#encfs-edit-form input[name=ENCDIR]", "/mnt/user/encStore/Photos")
        await page.fill("#encfs-edit-form input[name=MOUNTPOINT]", "/mnt/user/decStore")
        await page.click("#encfs-edit-form input[data-role=submit]")
        await page.wait_for_selector("#encfs-edit-dialog [data-role=error]:not([hidden])")
        print("validation error:", await page.inner_text("#encfs-edit-dialog [data-role=error]"))
        await page.fill("#encfs-edit-form input[name=MOUNTPOINT]", "/mnt/user/decStore/Photos")
        await page.select_option("#encfs-edit-form select[name=AUTOMOUNT]", "keyfile")
        await page.screenshot(path=f"{OUT}/06-edit.png")
        await page.select_option("#encfs-edit-form select[name=AUTOMOUNT]", "no")
        await page.click("#encfs-edit-form input[data-role=submit]")
        await page.wait_for_selector("#encfs-message-dialog[open]")
        print("after save:", await page.inner_text("#encfs-message-dialog [data-role=message]"))
        await page.click("#encfs-message-dialog input[value=Close]")
        await page.click("input[value=Initialize]")
        await page.fill("#encfs-init-form input[name=password]", "photos-pass-123")
        await page.fill("#encfs-init-form input[name=confirm]", "photos-pass-123")
        await page.check("#encfs-init-form input[name=ack]")
        await page.screenshot(path=f"{OUT}/07-init.png")
        await page.click("#encfs-init-form input[data-role=submit]")
        await page.wait_for_selector("#encfs-message-dialog[open]")
        await page.click("#encfs-message-dialog input[value=Close]")
        await page.screenshot(path=f"{OUT}/08-two-volumes.png", full_page=True)

        # Busy unmount -> force prompt
        import subprocess
        holder = subprocess.Popen(["bash", "-c", "cd /mnt/user/decStore/Photos && sleep 60"])
        await asyncio.sleep(0.5)
        rows = page.locator("#encfs-rows tr")
        await page.locator("#encfs-rows tr", has_text="Photos").locator("input[value=Unmount]").click()
        await page.wait_for_selector("#encfs-message-dialog[open]")
        await page.screenshot(path=f"{OUT}/09-busy.png")
        await page.click("#encfs-message-dialog input[value='Force unmount']")
        await page.wait_for_selector("#encfs-rows tr:has-text(\"Photos\") input[value=Mount]", timeout=20000)
        holder.kill()
        print("photos row:", await page.locator("#encfs-rows tr", has_text="Photos").inner_text())

        # Log
        await page.click("#encfs-log summary")
        await page.wait_for_timeout(800)
        await page.screenshot(path=f"{OUT}/10-log.png", full_page=True)

        # White theme and narrow viewport
        await page.goto(BASE + "?theme=white")
        await page.wait_for_selector("#encfs-rows tr td.encfs-actions")
        await page.screenshot(path=f"{OUT}/11-list-white.png", full_page=True)
        await page.set_viewport_size({"width": 400, "height": 900})
        await page.screenshot(path=f"{OUT}/12-mobile.png", full_page=True)

        print("console problems:", errors)
        await b.close()

asyncio.run(main())
