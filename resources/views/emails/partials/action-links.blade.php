{{-- The button row of the main app's emails when the MyFinance2 package (whose action-links partial this mirrors)
     is not installed. $links: list of ['label', 'url']. Inline colours too, for mail clients that drop <style>. --}}
<div class="actions">
    @foreach ($links as $link)
        <a href="{{ $link['url'] }}" class="action-link" target="_blank"
           style="background:#0d6efd;color:#fff !important;text-decoration:none;">{{ $link['label'] }}</a>
    @endforeach
</div>
