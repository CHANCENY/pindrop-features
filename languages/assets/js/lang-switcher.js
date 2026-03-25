document.addEventListener('DOMContentLoaded',async ()=>{
    const langSelectField = document.getElementById('languageSupport');
    if (langSelectField) {
        langSelectField.addEventListener('change',async (e)=>{
            const value = e.target.value;
            const response = await fetch('/internal/lang/switch',{
                method: "POST",
                body: JSON.stringify({lang: value, path: window.location.pathname})
            });
            const results = await response.json()
            alert("Switch language to "+ results.lang);
            window.location.href = results.path;
        })
        hidInframeLanguage()
    }

})
function googleTranslateElementInit() {
    // Fetch the default language from your backend
    fetch('/internal/lang/support/default')
        .then(response => response.json())
        .then(lang => {
            // Initialize Google Translate
            new google.translate.TranslateElement(
                {
                    pageLanguage: 'en',
                    layout: google.translate.TranslateElement.InlineLayout.HORIZONTAL
                },
                'google_translate_element'
            );
            setGoogleLanguage(lang.lang);
        })
        .catch(err => {
            console.error('Failed to fetch default language:', err);
        });
}

function setGoogleLanguage(langCode) {
    const target = document.getElementById('google_translate_element');

    if (!target) return;

    const observer = new MutationObserver(() => {
        const select = target.querySelector('.goog-te-combo');
        if (!select) return;

        // stop observing once found
        observer.disconnect();

        // ensure option exists
        const option = Array.from(select.options)
            .find(opt => opt.value === langCode);

        if (!option) {
            console.warn('Language not found:', langCode);
            return;
        }

        select.value = langCode;

        // important: use native Event
        const event = new Event('change', { bubbles: true });
        select.dispatchEvent(event);

        console.log('Language changed to:', langCode);
    });

    observer.observe(target, { childList: true, subtree: true });
}

function hidInframeLanguage() {
    let i = 0;
    let o = 0;
    const id = setInterval(()=>{
      const iframe = document.querySelector('iframe');
      o++;
      if (iframe && iframe.classList.contains('skiptranslate')) {
          iframe.parentElement.style.display = "none";
          document.body.style.top = "0px"
          i++;
          if (i === 100) {
              clearInterval(id);
          }
      }
        if (o === 200) {
            clearInterval(id);
        }
    },100);


}
