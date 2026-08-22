@php echo '<?xml version="1.0" encoding="UTF-8"?>'; @endphp
<rss version="2.0">
<channel>
    <title>{{ config('app.name') }} - EverQuest Patch History</title>
    <link>{{ route('patches.index') }}</link>
    <description>The newest records in the supplied EverQuest historical patch archive.</description>
    <language>en-us</language>
    <lastBuildDate>{{ $lastBuildDate }}</lastBuildDate>
    @foreach ($patches as $patch)
        <item>
            <title>{{ $patch['title'] }}</title>
            <link>{{ route('patches.show', $patch['slug']) }}</link>
            <description>{{ $patch['summary'] }}</description>
            <pubDate>{{ Carbon\CarbonImmutable::createFromFormat('!Y-m-d', $patch['patch_date'], 'UTC')->toRfc2822String() }}</pubDate>
            <guid isPermaLink="true">{{ route('patches.show', $patch['slug']) }}</guid>
        </item>
    @endforeach
</channel>
</rss>
