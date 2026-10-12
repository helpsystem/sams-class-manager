#!/usr/bin/env python3
"""English UI for the Class Manager files that live only on the server.

The class post type, the old class dashboard, the registrants list and the Zoom-link e-mail were first
written with Persian labels. The rule for the school is: every screen and every e-mail in English.
This script rewrites those labels in place (idempotent; safe to run on every deploy) and prints any
Persian that is still left outside comments, so nothing is missed.

Usage: python3 english-ui.py /path/to/wp-content/plugins/sams-class-manager
"""
import os
import re
import sys

PL = sys.argv[1]
FA = '؀-ۿ‌'

# Exact labels (as they appear on the screens). Longest first, matched only as whole Persian words.
MAP = {
    # class post type / old class dashboard
    'داشبورد کلاس‌های سام': 'Classes',
    'مدیریت کلاس‌ها': 'Classes',
    'کلاس‌ها / دوره‌ها': 'Classes',
    'ثبت دوره جدید': 'Add a class',
    'ویرایش دوره': 'Edit class',
    'مدیریت ثبت‌نامی‌ها': 'Students in this class',
    'عنوان': 'Title',
    'کد دوره': 'Course code',
    'بازه زمانی': 'Dates',
    'اکانت زوم ۱': 'Zoom account 1',
    'اکانت زوم ۲': 'Zoom account 2',
    'اکانت زوم': 'Zoom account',
    'ثبت‌نامی‌ها': 'Registrations',
    'وضعیت دوره': 'Class status',
    'وضعیت': 'Status',
    'عملیات': 'Actions',
    'پیش‌رو': 'Upcoming',
    'در حال برگزاری': 'In progress',
    'لغوشده': 'Cancelled',
    'تکمیل خودکار (بعد از تاریخ پایان)': 'Mark completed by itself after the end date',
    'فقط دستی توسط ساغر': 'Only by hand (school manager)',
    'جزئیات دوره': 'Class details',
    'پیشنهاد کد بعدی': 'Suggest the next code',
    'با همون الگویی که تا الان برای ۶۰-۷۰ دوره قبلی استفاده کردید هماهنگ نگه دارید. شماره شاگردها داخل همین کد دوره شمارش می‌شه.':
        'Use the same numbering the school has always used. Student numbers are counted inside this course code.',
    'شروع دوره': 'Class starts',
    'پایان دوره': 'Class ends',
    'روزها / ساعت‌ها (توضیح آزاد)': 'Days and times (as students should read them)',
    'ظرفیت': 'Seats',
    'قیمت ($)': 'Price ($)',
    '— انتخاب کنید —': '— choose —',
    'اکانتی که برای همین بازه زمانی قبلاً به دوره دیگری اختصاص داده شده، به‌صورت خودکار غیرفعال می‌شه تا تداخل پیش نیاد.':
        'A Zoom account already used by another class at the same time is greyed out, so two classes never share one account.',
    'لینک زوم (فعلاً دستی — در فاز بعد خودکار ساخته می‌شه)': 'Zoom link',
    'توضیح/لینک‌های اضافه برای ایمیل': 'Extra notes or links for the e-mail',
    'زمان‌بندی ارسال لینک/فرم': 'When the Zoom link and forms are e-mailed',
    'ارسال خودکار': 'Send automatically',
    'ساعت قبل از شروع دوره': 'hours before the class starts',
    'ارسال در تاریخ/ساعت مشخص:': 'Send at a set date and time:',
    'فقط برای ثبت‌نامی‌هایی که هم «ثبت‌نام» و هم «پرداخت» رو تکمیل کرده باشن، لینک زوم و فرم‌ها در همین زمان خودکار ایمیل می‌شه. همیشه می‌تونید از داشبورد دکمه «ارسال همین الان» رو هم بزنید.':
        'Only students who finished both the registration and the payment get the Zoom link and forms, by e-mail, at this time. You can also press “Send now to paid students” on the class’s student list at any time.',
    'مثلاً شنبه و یکشنبه، ۹ تا ۱۲': 'e.g. Mon–Fri, 6:00–9:15 PM',
    'مثلاً': 'e.g.',
    'کلاس‌های سام': 'Sam’s School',
    'همه دوره‌ها': 'All classes',
    'کلاس‌ها': 'Classes',
    'دوره': 'Class',
    'دسترسی مجاز نیست': 'You are not allowed to do this.',
    # registrants of one class
    '← بازگشت به همه دوره‌ها': '← All classes',
    'بازگشت به همه دوره‌ها': 'All classes',
    'ارسال همین الان برای پرداخت‌شده‌ها': 'Send now to paid students',
    'ارسال همین الان': 'Send now',
    'افزودن ثبت‌نامی جدید': 'Add a student to this class',
    'لیست ثبت‌نامی‌ها': 'Students in this class',
    'افزودن': 'Add',
    'شماره شاگرد': 'Student #',
    'نام شاگرد': 'Student name',
    'شماره تماس': 'Phone number',
    'فرم تکمیل‌شده': 'Form done',
    'ارسال مطالب': 'Materials sent',
    'پرداخت‌شده / ': 'paid / ',
    'پرداخت‌شده‌ها': 'paid students',
    'پرداخت‌شده': 'Paid',
    'تکمیل‌شده': 'Completed',
    'پرداخت': 'Payment',
    'ایمیل': 'E-mail',
    'تماس': 'Phone',
    'نام': 'Name',
    'تا': 'to',
    'کل': 'total',
}

# Lines whose Persian text is not shown on a screen (e-mails, errors, labels): rewritten by context.
RULES = {
    'includes/class-email.php': [
        (r"\$subject = sprintf\( '[^']*', \$title \);", "$subject = sprintf( 'Your Zoom class link — %s', $title );"),
        (r'\$body\s*=\s*"[^"]*"\s*\.\s*\$name\s*\.\s*"[^"]*";', '$body  = "Hi " . $name . ",\\n\\n";'),
        (r'\$body \.= "[^"]*" \. \$title \. "[^"]*" \. \$code \. "[^"]*";', '$body .= "Here is everything for your class “" . $title . "” (course " . $code . "):\\n\\n";'),
        (r'\$body \.= "[^"]*" \. \$zoom_link \. "\\n\\n";', '$body .= "Zoom link: " . $zoom_link . "\\n\\n";'),
        (r'\$body \.= "[^"]*\'s Driving School";', '$body .= "See you in class,\\nSam\'s Driving School";'),
    ],
    'includes/class-cpt-classes.php': [
        (r"('label'\s*=>\s*)'[^']*[" + FA + r"][^']*'", r"\1'Classes'"),
        (r"('singular_name'\s*=>\s*)'[^']*[" + FA + r"][^']*'", r"\1'Class'"),
        (r"('all_items'\s*=>\s*)'[^']*[" + FA + r"][^']*'", r"\1'All classes'"),
        (r"<strong>[^<]*[" + FA + r"][^<]*</strong>(\s*<\?php echo esc_html\( \$sent)", r"<strong>Last sent:</strong>\1"),
        (r"(wp_send_json_error\( )'[^']*[" + FA + r"][^']*'", r"\1'You are not allowed to do this.'"),
    ],
    'includes/class-admin-dashboard.php': [
        (r"echo '<p>[^'<]{25,}</p>';", "echo '<p>No classes yet. Press “Add a class” to create the first one.</p>';"),
        (r"echo '<p>[^'<]*[" + FA + r"][^'<]*</p>';", "echo '<p>This class could not be found.</p>';"),
        (r'(<td colspan="7">)[^<]*[' + FA + r'][^<]*(</td>)', r'\1No students in this class yet.\2'),
    ],
    'includes/class-cpt-registrants.php': [
        (r"'label'(\s*)=> '[^']*',", r"'label'\1=> 'Registrants',"),
        (r"'labels'(\s*)=> array\( 'name' => '[^']*', 'singular_name' => '[^']*' \),", r"'labels'\1=> array( 'name' => 'Registrants', 'singular_name' => 'Registrant' ),"),
        (r"(current_user_can\( 'edit_posts' \) \) \{ wp_send_json_error\( )'[^']*'", r"\1'You are not allowed to do this.'"),
        (r"(if \( ! \$class_id \) \{ wp_send_json_error\( )'[^']*'", r"\1'Choose a class first.'"),
        (r"^(\s*)wp_send_json_error\( '[" + FA + r"][^']*' \);", r"\1wp_send_json_error( 'Could not save. Please try again.' );"),
    ],
}

FILES = ['includes/class-cpt-classes.php', 'includes/class-cpt-registrants.php', 'includes/class-admin-dashboard.php',
         'includes/class-email.php', 'includes/class-cron.php', 'sams-class-manager.php']

word = lambda k: re.compile('(?<![' + FA + '])' + re.escape(k) + '(?![' + FA + '])')
ORDER = sorted(MAP, key=len, reverse=True)
has_fa = re.compile('[؀-ۿ]')


def is_comment(line):
    t = line.strip()
    return t.startswith(('//', '*', '/*', '#'))


for rel in FILES:
    p = os.path.join(PL, rel)
    if not os.path.isfile(p):
        continue
    src = open(p, encoding='utf-8').read()
    lines = src.split('\n')
    for i, l in enumerate(lines):
        if is_comment(l) or not has_fa.search(l):
            continue
        for rx, rep in RULES.get(rel, []):
            l = re.sub(rx, rep if '\\1' in rep else (lambda m, r=rep: r), l)
        for k in ORDER:
            if k in l:
                l = word(k).sub(MAP[k].replace('\\', '\\\\'), l)
        lines[i] = l
    out = '\n'.join(lines)
    if out != src:
        open(p, 'w', encoding='utf-8').write(out)
    left = [(n + 1, re.sub('[؀-ۿ‌][^\'"<>;]*', '<FA>', l.strip())[:150]) for n, l in enumerate(out.split('\n')) if has_fa.search(l) and not is_comment(l)]
    print('english-ui: %s %s' % (rel, 'ok' if not left else '%d Persian lines left' % len(left)))
    for n, l in left[:12]:
        print('   %d: %s' % (n, l))
