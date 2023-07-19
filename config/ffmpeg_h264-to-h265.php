#!/usr/bin/php
<?php declare(strict_types=1);

$targetDir = trim(getenv('TARGET'));
$targetFormat = trim(getenv('TARGET_FORMAT'));
$encodingDir = '/tmp/encoding';
$skipEncoding = ['hevc', 'av1'];
$fileExtConfig = trim(getenv('ALLOWED_SOURCE_FORMAT'));
$ffmpeg = trim(exec("which ffmpeg"));
$ffprobe = trim(exec("which ffprobe"));
$optionsConfig = trim(getenv('FFMPEG_CONFIG'));
$fileExt = array_map('trim', explode(',', $fileExtConfig));

$options = [];
foreach(explode(' -', $optionsConfig) as $opt) {
   $options[] = '-'.ltrim(trim($opt), '-');
}


var_dump($targetDir, $targetFormat, $encodingDir, $skipEncoding, $ffmpeg, $ffprobe, $fileExt, $options);


if (!file_exists($targetDir) || !file_exists($encodingDir)) {
die('invalid config. abort');
}


function execCommand(string $command): string
{
    return strtolower(trim((string)system($command)));
}

function getVideoEncoding(SplFileInfo $file): string
{
    global $ffprobe;
    return execCommand($ffprobe . ' -v error -select_streams v:0 -show_entries stream=codec_name -of default=noprint_wrappers=1:nokey=1 "' . $file . '"');
}

function validateFile(SplFileInfo $file): bool
{
    global $ffmpeg;

    return empty(execCommand($ffmpeg . ' -v error -i "'. $file .'" -f null -'));
}

function copyFilePermissions(SplFileInfo $source, SplFileInfo $target): void
{
    $perms = fileperms($source->getRealPath());
    if ($perms !== false) {
        chmod((string)$target, $perms);
    }
}

function encodeFile(SplFileInfo $source, SplFileInfo $target): void
{
    global $ffmpeg, $options;
    $encodingOptions = ['-i "'. $source .'"']; // input
    array_push($encodingOptions, ...$options);  // encoding options
    $encodingOptions[] = '"'. $target .'"'; // output
    execCommand($ffmpeg . ' '. implode(' ', $encodingOptions));
}

function copyFile(SplFileInfo $source, SplFileInfo $target): bool
{
    return copy((string)$source, (string)$target);
}

function renameFile(SplFileInfo $source, SplFileInfo $target): void
{
    rename((string)$source, (string)$target);
}

function emptyDirectory(string $directory): void
{
    $files = glob(rtrim($directory, '/').'/*');
    foreach($files as $file){ // iterate files
        if(is_file($file)) {
            unlink($file); // delete file
        }
    }
}

function removeFile(SplFileInfo $file): bool
{
    if (is_file((string)$file) && unlink((string)$file)) {
        return !file_exists((string)$file);
    }

    return false;
}

function getTempEncodingLocation(SplFileInfo $file, string $targetFormat = '', string $prefix = ''): ?SplFileInfo
{
    global $encodingDir;
    $file = $encodingDir . DIRECTORY_SEPARATOR . $prefix . md5((string)$file) . '.' . $targetFormat;
    if (file_exists($file))
        unlink($file);

    return new SplFileInfo($file);
}

function removeEncodingHintsFromBasename(SplFileInfo $file, string $targetFormat = '', string $prefix = ''): SplFileInfo
{
    $permutations = [
        'h264', 'H264', 'h.264', 'H.264', 'h_264', 'H_264', 'h 264', 'H 264',
        'xvid', 'Xvid', 'XVid', 'XVId', 'XVID', 'XviD',
        '__', '()', '[]', '(_)'
    ];
    $basename = str_replace($permutations, '', $file->getBasename($file->getExtension()));
    $basename = str_replace($permutations, '', $basename);
    $basename = str_replace($permutations, '', $basename);
    return new SplFileInfo($file->getPath() . DIRECTORY_SEPARATOR . $prefix . trim($basename, '.') . '.' . $targetFormat);
}

function main(): void
{
    global $targetDir, $fileExt, $skipEncoding, $targetFormat, $encodingDir;

    emptyDirectory($encodingDir);

    $dirIte = new RecursiveDirectoryIterator($targetDir);
    $ite = new RecursiveIteratorIterator($dirIte);

    foreach ($ite as $file) {
        if ($file instanceof SplFileInfo && in_array($file->getExtension(), $fileExt)) {

            echo PHP_EOL . '#################################' . PHP_EOL . PHP_EOL;

            echo 'process: '. $file . PHP_EOL;
            // skip this encoding types
            if (in_array(($encoding = getVideoEncoding($file)), $skipEncoding) || $encoding === '') {
                echo 'file encoding not invalid: ('. $encoding .') ->'. $file . PHP_EOL;
                continue;
            }

            $encodingSourceFile = getTempEncodingLocation($file, $targetFormat, 'h264_');
            $encodingTargetFile = getTempEncodingLocation($file, $targetFormat, 'hvec_');
            if (!$encodingTargetFile || !$encodingSourceFile) {
                removeFile($encodingSourceFile);
                removeFile($encodingTargetFile);
                echo 'failed to create tmp file, skip...' . PHP_EOL;
                continue;
            }

            $realTargetFileCopy = removeEncodingHintsFromBasename($file, $targetFormat, '_hvec_');
            $realTargetFile = removeEncodingHintsFromBasename($file, $targetFormat);

            echo 'copy source file: '. $file->getBasename() . ' -> ' . $encodingSourceFile. PHP_EOL;
            if(!copyFile($file, $encodingSourceFile)) {
                echo 'copy failed, skip...' . PHP_EOL;
                continue;
            }

            echo 'start encode' . PHP_EOL;
            encodeFile($encodingSourceFile, $encodingTargetFile);
            echo 'copy encoded file: '. $encodingTargetFile . ' -> ' . $realTargetFileCopy. PHP_EOL;
            copyFile($encodingTargetFile, $realTargetFileCopy);
            echo 'remove tmp files' . PHP_EOL;
            removeFile($encodingTargetFile);
            removeFile($encodingSourceFile);

            echo 'validate file: ' . $realTargetFileCopy . PHP_EOL;
            if (getVideoEncoding($realTargetFileCopy) === 'hevc' && validateFile($realTargetFileCopy)) {
                echo 'file valid, remove original file' . PHP_EOL;
                copyFilePermissions($file, $realTargetFileCopy);
                removeFile($file);
                renameFile($realTargetFileCopy, $realTargetFile);
            } else {
                removeFile($realTargetFileCopy);
                echo 'file invalid, skip & clean up' . PHP_EOL;
            }
        }
    }
}




main();
exit(0);
