
## What is it
It's a WordPress plugin which provides a wrapper for [rosell-dk/webp-convert](https://github.com/rosell-dk/webp-convert) library.
It helps do integration into WordPress, provides hooks and setups a cron job.

## How to use
Download and activate the plugin.

Supported actions list:
- webpconverter__setup_settings
- webpconverter__content_start
- webpconverter__content_end
- webpconverter__setup_settings

Supported filters list:
- webpconverter__is_active
- webpconverter__get_source

Calling the `webpconverter__get_source` hook will return a webp version of an image, or will add the image into the waiting list, which will be processing during cron calls.