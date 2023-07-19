# docker-ffmpeg-batch-hevc-encoder
Encodes H.264 to H.265(HEVC) via ffmpeg. Main Purpose is it to encode large Libraries of Video files automatically  

## Tested on Linux (Ubuntu 22.04) & M2.Max Mac (13.4)
* Docker version 24.0.4, build 3713ee1
* Docker Compose version v2.19.1
* linuxserver/ffmpeg:latest (6.0)

## Howto use
use .env file or update docker-compose.yml environment fields to what you want.                      
Update the volume: ```"/volumes/MyLibrary:/videos"``` pointing to your your Media Library

## Startup
```docker compose build```              
```docker compose up```
and the magic automatic happen

if you want see what happen 
```docker compose logs```


### Nice to Know
Be aware that every TO_ENCODE_FILE one by one will be copied into the TMPFS (have enough ram or update the php script to let it encode onn disk).          

encoding will happen in /tmp/encoding (tmpfs) to reduce constant diskIO especially for network/NAS systems nice to have. 

It will automaic skip HEVC encoded file.


#### Logic
1. find matching file in Library.
2. Copy file into /tmp/encoding (tmpfs)
3. encode file output into /tmp/encoding (tmpfs)
4. copy encoded file back to original location (different name)
5. clean up /tmp/encoding (tmpfs)
6. check via ffmpeg if new encoded file is a valid video
7. Delete Original File
8. Rename encoded file to Original file (automatic remove "h254" name scheme tags like: ```[HorribleSubs] Rewrite - 08 [h264][720p].mkv``` -> ```[HorribleSubs] Rewrite - 08 [720p].mkv```)
