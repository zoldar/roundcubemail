$(function() {
    if (window.location.search.match(/fromOld=true/)) {
        $("body").prepend('<div id="from-old-box"><div class="inner">Twoje konto zostało przeniesione na nowy serwer. Od tej chwili dostęp do skrzynki przez WWW jest realizowany przez nowego klienta poczty WWW.</div></div>');
    }
});
