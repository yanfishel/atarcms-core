<?php

declare(strict_types=1);

namespace AtarCms\Demo;

/**
 * The few real words of the demo content (section, page and tag names, the site's name and contact details, the
 * accessibility statement and the privacy policy) in English, Russian and Hebrew; any other language gets lorem-ipsum
 * names (LoremText) and the English contact details and texts. Addresses are those of the slugs: Latin, the same in
 * every language.
 */
final class DemoTexts
{
    /** Top sections (slug => names) with their two subsections each. */
    public const array SECTIONS = [
        'services' => [
            'names' => ['en' => 'Services', 'ru' => 'Услуги', 'he' => 'שירותים'],
            'children' => [
                'consulting' => ['en' => 'Consulting', 'ru' => 'Консультации', 'he' => 'ייעוץ'],
                'workshops' => ['en' => 'Workshops', 'ru' => 'Мастерские', 'he' => 'סדנאות'],
            ],
        ],
        'projects' => [
            'names' => ['en' => 'Projects', 'ru' => 'Проекты', 'he' => 'פרויקטים'],
            'children' => [
                'residential' => ['en' => 'Residential', 'ru' => 'Жилые', 'he' => 'מגורים'],
                'public-spaces' => ['en' => 'Public spaces', 'ru' => 'Общественные пространства', 'he' => 'מרחבים ציבוריים'],
            ],
        ],
        'company' => [
            'names' => ['en' => 'Company', 'ru' => 'Компания', 'he' => 'החברה'],
            'children' => [
                'team' => ['en' => 'Team', 'ru' => 'Команда', 'he' => 'הצוות'],
                'history' => ['en' => 'History', 'ru' => 'История', 'he' => 'היסטוריה'],
            ],
        ],
    ];

    /** Pages of the collection routed at the root (slug => titles), besides its preset pages (filled by DemoSeeder). */
    public const array PAGES = [
        'about' => ['en' => 'About us', 'ru' => 'О нас', 'he' => 'אודות'],
    ];

    /** Tags of the news, per language. */
    public const array TAGS = [
        'en' => ['Events', 'Updates', 'Community', 'Guides'],
        'ru' => ['События', 'Обновления', 'Сообщество', 'Руководства'],
        'he' => ['אירועים', 'עדכונים', 'קהילה', 'מדריכים'],
    ];

    public const array SITE_NAME = ['en' => 'Atar Demo', 'ru' => 'Атар Демо', 'he' => 'אתר הדגמה'];

    public const array SHORT_NAME = ['en' => 'Atar', 'ru' => 'Атар', 'he' => 'אתר'];

    public const array META_TITLE = [
        'en' => 'Atar Demo — a small site made with AtarCMS',
        'ru' => 'Атар Демо — небольшой сайт на AtarCMS',
        'he' => 'אתר הדגמה — אתר קטן שנבנה עם AtarCMS',
    ];

    public const array META_DESCRIPTION = [
        'en' => 'A demo site with sections, articles, news and a contact form, filled with placeholder text.',
        'ru' => 'Демонстрационный сайт с разделами, статьями, новостями и формой обратной связи, заполненный текстом-рыбой.',
        'he' => 'אתר הדגמה עם מדורים, כתבות, חדשות וטופס יצירת קשר, מלא בטקסט לדוגמה.',
    ];

    public const array ADDRESS = [
        'en' => "12 Harbour Street\nTel Aviv-Yafo",
        'ru' => "ул. Гаванская, 12\nТель-Авив-Яффо",
        'he' => "רחוב הנמל 12\nתל אביב-יפו",
    ];

    public const array HOURS = [
        'en' => "Sun–Thu 9:00–18:00\nFri 9:00–13:00",
        'ru' => "Вс–Чт 9:00–18:00\nПт 9:00–13:00",
        'he' => "א׳–ה׳ 9:00–18:00\nו׳ 9:00–13:00",
    ];

    public const array ALT = ['en' => 'Demo picture', 'ru' => 'Демонстрационная картинка', 'he' => 'תמונת הדגמה'];

    /**
     * The home page's slides (a slides field), per language: the first has a button to the news archive, the second no
     * link, the third links the "about" page as a whole.
     */
    public const array SLIDES = [
        [
            'title' => ['en' => 'Welcome to Atar', 'ru' => 'Добро пожаловать в Атар', 'he' => 'ברוכים הבאים'],
            'subtitle' => [
                'en' => 'Sections, news and a contact form, ready in every language.',
                'ru' => 'Разделы, новости и форма связи — сразу на всех языках.',
                'he' => 'מדורים, חדשות וטופס יצירת קשר, מוכנים בכל שפה.',
            ],
            'button' => ['en' => 'Read the news', 'ru' => 'Читать новости', 'he' => 'לחדשות'],
        ],
        [
            'title' => ['en' => 'Made for small teams', 'ru' => 'Для небольших команд', 'he' => 'בנוי לצוותים קטנים'],
            'subtitle' => [
                'en' => 'Edit pages, slides and menus without a developer.',
                'ru' => 'Редактируйте страницы, слайды и меню без программиста.',
                'he' => 'עורכים דפים, שקופיות ותפריטים בלי מתכנת.',
            ],
        ],
        [
            'title' => ['en' => 'Get to know us', 'ru' => 'Познакомьтесь с нами', 'he' => 'הכירו אותנו'],
            'subtitle' => ['en' => 'Who we are and how we work.', 'ru' => 'Кто мы и как работаем.', 'he' => 'מי אנחנו ואיך אנחנו עובדים.'],
        ],
    ];

    public const array LEAD = [
        'en' => 'A tidy little site for a studio, a club or a clinic: sections, news, a contact form and a map, in every language you need.',
        'ru' => 'Аккуратный небольшой сайт для студии, клуба или клиники: разделы, новости, форма связи и карта — на всех нужных языках.',
        'he' => 'אתר קטן ומסודר לסטודיו, למועדון או למרפאה: מדורים, חדשות, טופס יצירת קשר ומפה, בכל שפה שתצטרכו.',
    ];

    /**
     * The accessibility statement (a template for the site's own): the commitment and the standard, what the site
     * does, its known limits and how to reach the coordinator. {site}, {email} and {phone} are filled by statement().
     */
    public const array STATEMENT = [
        'en' => '<h2>Our commitment</h2>'
            . '<p>{site} wants every visitor to be able to use this site, people with disabilities included. We follow the Web Content Accessibility Guidelines (WCAG) 2.1 at level AA, the reference of the Israeli standard SI 5568.</p>'
            . '<h2>What we have done</h2>'
            . '<ul><li>Pages have a clear structure of headings and regions, and a link at the top skips straight to the content.</li>'
            . '<li>Everything works with the keyboard alone, and the focus is always visible.</li>'
            . '<li>Pictures have text alternatives, and the colours have enough contrast in the light and the dark theme.</li>'
            . '<li>Every page follows the direction of its language, right to left included.</li>'
            . '<li>The accessibility button in the corner of every page changes the text size, spacing, font and colours, highlights links and headings, enlarges the cursor, stops animations, shows a reading guide and hides images. Your choice is remembered on this device.</li></ul>'
            . '<h2>Known limits</h2>'
            . '<p>Some content may not be fully accessible yet: older documents and files, maps and videos embedded from other services, and pictures added without a description. We keep improving them.</p>'
            . '<h2>Contact the accessibility coordinator</h2>'
            . '<p>If something on this site is hard to use, or you need information in another format, tell us: e-mail {email}, phone {phone}. Name the page and describe the problem, and we will answer as soon as we can.</p>'
            . '<p><em>This is a demo statement: replace it with your own after checking the site.</em></p>',
        'ru' => '<h2>Наши обязательства</h2>'
            . '<p>{site} стремится к тому, чтобы сайтом мог пользоваться каждый посетитель, в том числе люди с инвалидностью. Мы следуем рекомендациям WCAG 2.1 (Web Content Accessibility Guidelines) уровня AA, на которых основан израильский стандарт SI 5568.</p>'
            . '<h2>Что сделано</h2>'
            . '<ul><li>У страниц понятная структура заголовков и областей, а ссылка в начале страницы ведёт прямо к содержимому.</li>'
            . '<li>Всем можно пользоваться с одной клавиатуры, и фокус всегда виден.</li>'
            . '<li>У изображений есть текстовые описания, цвета достаточно контрастны в светлой и тёмной теме.</li>'
            . '<li>Каждая страница следует направлению письма своего языка, в том числе справа налево.</li>'
            . '<li>Кнопка доступности в углу каждой страницы меняет размер текста, интервалы, шрифт и цвета, выделяет ссылки и заголовки, увеличивает курсор, останавливает анимацию, показывает линейку для чтения и скрывает изображения. Выбор запоминается на этом устройстве.</li></ul>'
            . '<h2>Известные ограничения</h2>'
            . '<p>Часть материалов пока может быть доступна не полностью: старые документы и файлы, карты и видео других сервисов, изображения, добавленные без описания. Мы продолжаем их улучшать.</p>'
            . '<h2>Связь с ответственным за доступность</h2>'
            . '<p>Если чем-то на сайте трудно пользоваться или вам нужны сведения в другом виде, напишите нам: e-mail {email}, телефон {phone}. Укажите страницу и опишите проблему, и мы ответим как можно скорее.</p>'
            . '<p><em>Это демонстрационный текст: замените его своим после проверки сайта.</em></p>',
        'he' => '<h2>המחויבות שלנו</h2>'
            . '<p>{site} פועל כדי שכל מבקר יוכל להשתמש באתר, כולל אנשים עם מוגבלות. האתר מותאם להנחיות WCAG 2.1 ברמה AA, שעליהן מבוסס התקן הישראלי ת״י 5568.</p>'
            . '<h2>מה עשינו</h2>'
            . '<ul><li>לעמודים מבנה ברור של כותרות ואזורים, וקישור בראש העמוד מדלג ישר לתוכן.</li>'
            . '<li>אפשר להשתמש בכל האתר באמצעות המקלדת בלבד, והמיקוד תמיד גלוי.</li>'
            . '<li>לתמונות יש חלופה טקסטואלית, ולצבעים ניגודיות מספקת בערכה הבהירה ובכהה.</li>'
            . '<li>כל עמוד נכתב בכיוון של השפה שלו, כולל מימין לשמאל.</li>'
            . '<li>כפתור הנגישות בפינת כל עמוד משנה את גודל הטקסט, הריווח, הגופן והצבעים, מדגיש קישורים וכותרות, מגדיל את הסמן, עוצר אנימציות, מציג סרגל קריאה ומסתיר תמונות. הבחירה נשמרת במכשיר הזה.</li></ul>'
            . '<h2>מגבלות ידועות</h2>'
            . '<p>חלק מהתוכן עדיין לא נגיש במלואו: מסמכים וקבצים ישנים, מפות וסרטונים משובצים משירותים אחרים ותמונות שנוספו בלי תיאור. אנחנו ממשיכים לשפר אותם.</p>'
            . '<h2>פנייה לרכז הנגישות</h2>'
            . '<p>נתקלת בקושי באתר או שאתה צריך מידע בפורמט אחר? כתוב לנו: דוא״ל {email}, טלפון {phone}. ציין את העמוד ותאר את הבעיה, ונחזור אליך בהקדם.</p>'
            . '<p><em>זהו טקסט הדגמה: החלף אותו בהצהרה משלך אחרי בדיקת האתר.</em></p>',
    ];

    /**
     * The privacy policy (a template for the site's own): what the contact form keeps and for how long, the e-mail it
     * sends, the hashed address that limits sending, what the browser keeps (only the theme and accessibility choices),
     * the embedded map and videos and their cookies, and how to reach the owner. {site}, {email} and {phone} are filled
     * by privacy().
     */
    public const array PRIVACY = [
        'en' => '<h2>Who we are</h2>'
            . '<p>This site belongs to {site}. This page explains what we learn about you when you visit it and what we do with it.</p>'
            . '<h2>The contact form</h2>'
            . '<p>When you send us a message, we keep your name, e-mail address, phone number (if you give one) and the message itself, and send them to our e-mail box so that we can answer you. We keep a message until we have dealt with it and delete it when it is no longer needed. We never sell or share it.</p>'
            . '<p>To stop junk mail, each message also keeps a scrambled code made from your internet address (never the address itself), which only tells the site how many messages came from the same place.</p>'
            . '<h2>Cookies and your browser</h2>'
            . '<p>The site sets no cookies for visitors and does not track you. Your browser remembers only the choices you make here, the light or dark theme and the accessibility settings, on your own device; you can clear them at any time in your browser.</p>'
            . '<h2>Maps and videos from other services</h2>'
            . '<p>The map on the contacts page comes from Google Maps, and some pages show videos from YouTube or Vimeo. When such a part of a page loads, your browser connects to that service, which may set its own cookies under its own privacy policy.</p>'
            . '<h2>Your rights and how to contact us</h2>'
            . '<p>You may ask us what we keep about you, ask us to correct it or to delete it: e-mail {email} or call {phone}.</p>'
            . '<p><em>This is a demo policy: replace it with your own, checked against the law that applies to your site.</em></p>',
        'ru' => '<h2>Кто мы</h2>'
            . '<p>Этот сайт принадлежит «{site}». Здесь рассказано, что мы узнаём о вас, когда вы заходите на сайт, и что с этим делаем.</p>'
            . '<h2>Форма обратной связи</h2>'
            . '<p>Когда вы отправляете нам сообщение, мы сохраняем ваше имя, адрес электронной почты, телефон (если вы его указали) и сам текст и пересылаем их на нашу почту, чтобы ответить вам. Сообщение хранится, пока мы не ответим на него, а затем удаляется. Мы никому его не продаём и не передаём.</p>'
            . '<p>Чтобы защититься от спама, вместе с сообщением хранится зашифрованный код вашего интернет-адреса (но не сам адрес): по нему сайт видит только, сколько сообщений пришло из одного места.</p>'
            . '<h2>Cookies и ваш браузер</h2>'
            . '<p>Сайт не ставит посетителям cookies и не следит за ними. Браузер запоминает только сделанный здесь выбор — светлую или тёмную тему и настройки доступности — и только на вашем устройстве; их можно стереть в настройках браузера.</p>'
            . '<h2>Карты и видео других сервисов</h2>'
            . '<p>Карта на странице контактов загружается с Google Maps, а на некоторых страницах есть видео с YouTube или Vimeo. Когда такая часть страницы загружается, браузер обращается к этому сервису, и тот может поставить свои cookies по своим правилам конфиденциальности.</p>'
            . '<h2>Ваши права и как с нами связаться</h2>'
            . '<p>Вы можете спросить, что мы о вас храним, попросить исправить или удалить эти сведения: напишите на {email} или позвоните по номеру {phone}.</p>'
            . '<p><em>Это демонстрационный текст: замените его своей политикой, проверенной на соответствие законам, которые действуют для вашего сайта.</em></p>',
        'he' => '<h2>מי אנחנו</h2>'
            . '<p>האתר הזה שייך ל{site}. בעמוד הזה מוסבר מה אנחנו יודעים עליך כשאתה מבקר באתר ומה אנחנו עושים עם המידע.</p>'
            . '<h2>טופס יצירת הקשר</h2>'
            . '<p>כשאתה שולח לנו הודעה, אנחנו שומרים את השם, כתובת הדוא״ל, הטלפון (אם מסרת) ואת ההודעה עצמה, ושולחים אותם לתיבת הדואר שלנו כדי שנוכל לענות לך. ההודעה נשמרת עד שנטפל בה, ונמחקת כשאין בה עוד צורך. אנחנו לא מוכרים ולא מעבירים אותה לאף אחד.</p>'
            . '<p>כדי לעצור דואר זבל, עם כל הודעה נשמר גם קוד מוצפן שנוצר מכתובת האינטרנט שלך (לעולם לא הכתובת עצמה), שמראה לאתר רק כמה הודעות הגיעו מאותו מקום.</p>'
            . '<h2>עוגיות והדפדפן שלך</h2>'
            . '<p>האתר לא שומר עוגיות אצל המבקרים ולא עוקב אחריהם. הדפדפן זוכר רק את הבחירות שעשית כאן, ערכה בהירה או כהה והגדרות הנגישות, במכשיר שלך בלבד; אפשר למחוק אותן בכל עת בדפדפן.</p>'
            . '<h2>מפות וסרטונים משירותים אחרים</h2>'
            . '<p>המפה בעמוד יצירת הקשר מגיעה מ־Google Maps, ובחלק מהעמודים יש סרטונים מ־YouTube או מ־Vimeo. כשחלק כזה של העמוד נטען, הדפדפן מתחבר לשירות הזה, שעשוי לשמור עוגיות משלו לפי מדיניות הפרטיות שלו.</p>'
            . '<h2>הזכויות שלך ויצירת קשר</h2>'
            . '<p>אפשר לשאול אותנו מה אנחנו שומרים עליך, לבקש לתקן את המידע או למחוק אותו: כתוב ל־{email} או התקשר ל־{phone}.</p>'
            . '<p><em>זהו טקסט הדגמה: החלף אותו במדיניות משלך, שנבדקה מול החוק שחל על האתר שלך.</em></p>',
    ];

    /**
     * The statement in $locale (English for a language without its own), with the site's name, e-mail (a link) and
     * phone (left to right in any language) filled in.
     */
    public static function statement(string $locale, string $site, string $email, string $phone): string
    {
        return self::filled(self::STATEMENT, $locale, $site, $email, $phone);
    }

    /**
     * The privacy policy in $locale, filled in like statement().
     */
    public static function privacy(string $locale, string $site, string $email, string $phone): string
    {
        return self::filled(self::PRIVACY, $locale, $site, $email, $phone);
    }

    /**
     * @param array<string, string> $texts
     */
    private static function filled(array $texts, string $locale, string $site, string $email, string $phone): string
    {
        $escape = static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return strtr(self::in($texts, $locale) ?? $texts['en'], [
            '{site}' => $escape($site),
            '{email}' => '<a href="mailto:' . $escape($email) . '">' . $escape($email) . '</a>',
            // No break inside the number.
            '{phone}' => '<span dir="ltr">' . str_replace(' ', "\u{00A0}", $escape($phone)) . '</span>',
        ]);
    }

    /**
     * $texts[$locale], or null for a language without its own (the caller falls back to English or lorem ipsum).
     *
     * @param array<string, string> $texts
     */
    public static function in(array $texts, string $locale): ?string
    {
        return $texts[$locale] ?? null;
    }
}
