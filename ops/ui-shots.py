import asyncio
from playwright.async_api import async_playwright
B='http://127.0.0.1:8000'
async def main():
    async with async_playwright() as p:
        b=await p.chromium.launch()
        ctx=await b.new_context(viewport={'width':390,'height':844}, device_scale_factor=2)
        pg=await ctx.new_page()
        await pg.goto(B+'/login'); await pg.fill('#email','ui@check.test'); await pg.fill('#password','ui-check-pass-1'); await pg.press('#password','Enter'); await pg.wait_for_timeout(2500)
        await pg.goto(B+'/dashboard'); await pg.wait_for_timeout(1500)
        await pg.locator('text=Anatomy — التشريح').first.click(); await pg.wait_for_timeout(2000)
        await pg.evaluate("window.scrollTo(0, 600)"); await pg.wait_for_timeout(500)
        await pg.screenshot(path='shots/names.png')
        await b.close()
asyncio.run(main())
