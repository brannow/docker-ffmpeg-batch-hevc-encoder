FROM linuxserver/ffmpeg:latest

RUN apt-get update && apt-get install -y --no-install-recommends \
php \
&& \
apt-get clean && \
rm -rf /var/lib/apt/lists/*

COPY ffmpeg_h264-to-h265.php ffmpeg_h264-to-h265.php


ENTRYPOINT ["./ffmpeg_h264-to-h265.php"]
