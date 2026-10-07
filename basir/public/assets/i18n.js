// بصير — نصوص التطبيق بالعربية (الافتراضية) والإنجليزية. كل ما يُنطق أو يُعرض للكفيف يمر من هنا.

const ar = {
  // حالات الزر الرئيسي
  'mode.loading': 'جارٍ التحميل…',
  'mode.idle': 'اضغط وتكلّم',
  'mode.idle.sub': 'قل: ابدأ — ماذا أمامي — اقرأ — أربع جهات — فيديو',
  'mode.idleNoStt': 'اضغط لبدء التنقل',
  'mode.idleNoStt.sub': 'ضغطة أخرى توقفه',
  'mode.listening': 'أستمع إليك…',
  'mode.listening.sub': 'تكلّم الآن',
  'mode.thinking': 'لحظة…',
  'mode.cancel.sub': 'اضغط للإلغاء',
  'mode.survey': 'جارٍ التصوير…',
  'mode.nav': 'التنقل يعمل',
  'mode.nav.sub': 'اضغط وقل: توقف',
  'mode.navNoStt.sub': 'اضغط لإيقافه',
  'mode.error': 'تعذر التشغيل',

  // الترحيب والمعلومات
  provider: ({ label, model, hasKey }) => `المُوَفِّرُ المَحْفُوظُ: ${label}، وَالنَّمُوذَجُ: ${model}. ${hasKey ? 'المِفْتَاحُ مَحْفُوظٌ عَلَى الخَادِمِ، وَلَا تَحْتَاجُ لِإِدْخَالِهِ مَرَّةً أُخْرَى.' : 'هَذَا المُوَفِّرُ لَا يَحْتَاجُ مِفْتَاحًا.'}`,
  'devices.none': ({ link }) => `لَا تُوجَدُ جَوَّالَاتٌ إِضَافِيَّةٌ مُتَّصِلَةٌ. لِرَبْطِ جَوَّالٍ: افْتَحْ عَلَى الجَوَّالِ الآخَرِ الرَّابِطَ ${link}، وَاخْتَرِ اتِّجَاهَهُ: أَمَام، أَوْ يَمِين، أَوْ خَلْف، أَوْ يَسَار. يُمْكِنُ رَبْطُ حَتَّى أَرْبَعَةِ جَوَّالَاتٍ.`,
  'devices.some': ({ count, names }) => `${['', 'جَوَّالٌ إِضَافِيٌّ مُتَّصِلٌ', 'جَوَّالَانِ إِضَافِيَّانِ مُتَّصِلَانِ', 'ثَلَاثَةُ جَوَّالَاتٍ إِضَافِيَّةٍ مُتَّصِلَةٌ', 'أَرْبَعَةُ جَوَّالَاتٍ إِضَافِيَّةٍ مُتَّصِلَةٌ'][count] || count}: ${names}. أَسْتَخْدِمُ صُوَرَهَا مَعَ كُلِّ إِرْشَادٍ.`,
  'welcome.first': ({ provider }) => `مَرْحَبًا، أَنَا بَصِير. ${provider}`,
  'welcome.back': ({ label, model }) => `بَصِيرٌ جَاهِزٌ. المُوَفِّرُ: ${label}، النَّمُوذَجُ: ${model}.`,
  'welcome.memory': ' لَدَيَّ وَصْفٌ مَحْفُوظٌ لِهَذَا المَكَانِ.',
  'welcome.tapTalkFirst': ' اضْغَطْ فِي أَيِّ مَكَانٍ عَلَى الشَّاشَةِ، وَتَكَلَّمْ بَعْدَ الصَّفَّارَةِ. قُلْ: ابْدَأْ، لِأُرْشِدَكَ فِي المَشْيِ، أَوْ قُلْ: مُسَاعَدَة.',
  'welcome.tapTalk': ' اضْغَطْ وَتَكَلَّمْ.',
  'welcome.tapToggle': ' اضْغَطْ فِي أَيِّ مَكَانٍ عَلَى الشَّاشَةِ لِبَدْءِ التَّنَقُّلِ أَوْ إِيقَافِهِ.',
  'welcome.firstTime': ' هَذِهِ أَوَّلُ مَرَّةٍ. لِأَتَعَرَّفَ عَلَى المَكَانِ قُلْ: فِيدْيُو، ثُمَّ اسْتَدِرْ حَوْلَ نَفْسِكَ بِبُطْءٍ. أَوْ قُلْ: أَرْبَعُ جِهَاتٍ، لِأُصَوِّرَ كُلَّ جِهَةٍ. أَوْ قُلْ: تَخَطِّي.',
  'welcome.install': ' يُمْكِنُكَ تَثْبِيتُ التَّطْبِيقِ عَلَى جِهَازِكَ: قُلْ تَثْبِيت.',
  help: 'الأَوَامِرُ: ابْدَأْ، لِلتَّنَقُّلِ. خُذْنِي إِلَى البَابِ، لِلتَّوَجُّهِ إِلَى هَدَفٍ. تَوَقَّفْ. مَاذَا أَمَامِي. اقْرَأْ، لِقِرَاءَةِ النَّصِّ. أَرْبَعُ جِهَاتٍ، أَوْ فِيدْيُو، لِلتَّعَرُّفِ عَلَى المَكَانِ. وَيْنَ الكُرْسِي، أَوْ أَيُّ سُؤَالٍ عَمَّا أَمَامَكَ. المُوَفِّر. الجَوَّالَات. انْسَ المَكَانَ. أَسْرِعْ، أَوْ أَبْطِئْ، لِسُرْعَةِ الكَلَامِ. كَرِّرْ. إِنْجْلِيزِي، لِلتَّحَدُّثِ بِالإِنْجْلِيزِيَّةِ. الإِعْدَادَات.',
  installed: 'تَمَّ تَثْبِيتُ التَّطْبِيقِ. يُمْكِنُكَ فَتْحُهُ مِنَ الشَّاشَةِ الرَّئِيسِيَّةِ.',
  'server.openSetup': ' — افتح الإعدادات لتحديد عنوان الخادم.',
  insecure: 'تنبيه: الكاميرا والميكروفون يحتاجان رابطاً آمناً (HTTPS) أو فتح التطبيق من نفس الجهاز عبر localhost. راجع ملف README.',
  tapToStart: 'اضغط في أي مكان على الشاشة للبدء.',

  // الاستماع والأوامر
  cancelled: 'تَمَّ الإِلْغَاءُ.',
  navStopped: 'تَوَقَّفَ التَّنَقُّلُ.',
  didntHear: 'لَمْ أَسْمَعْ شَيْئًا. اضْغَطْ وَتَكَلَّمْ مَرَّةً أُخْرَى.',
  'mic.denied': 'لَمْ يُسْمَحْ بِاسْتِخْدَامِ المِيكْرُوفُونِ. اسْمَحْ بِهِ مِنْ إِعْدَادَاتِ المُتَصَفِّحِ.',
  'mic.none': 'لَا يُوجَدُ مِيكْرُوفُونٌ يَعْمَلُ.',
  'mic.network': 'التَّعَرُّفُ عَلَى الكَلَامِ يَحْتَاجُ إِلَى الإِنْتَرْنِت. حَاوِلْ مَرَّةً أُخْرَى.',
  'mic.unavailable': 'الأَوَامِرُ الصَّوْتِيَّةُ غَيْرُ مُتَاحَةٍ فِي هَذَا المُتَصَفِّحِ. اضْغَطْ عَلَى الشَّاشَةِ لِبَدْءِ التَّنَقُّلِ، وَضَغْطَةٌ أُخْرَى لِإِيقَافِهِ.',
  'mic.failed': 'تَعَذَّرَ تَشْغِيلُ المِيكْرُوفُونِ. اضْغَطْ وَحَاوِلْ مَرَّةً أُخْرَى.',
  stopped: 'تَوَقَّفْتُ.',
  nothingToRepeat: 'لَا يُوجَدُ مَا أُكَرِّرُهُ.',
  openingSettings: 'سَأَفْتَحُ صَفْحَةَ الإِعْدَادَاتِ. تَحْتَاجُ مُسَاعِدًا مُبْصِرًا لِإِكْمَالِهَا.',
  forgot: 'نَسِيتُ وَصْفَ المَكَانِ.',
  faster: 'أَصْبَحَ الكَلَامُ أَسْرَعَ.',
  slower: 'أَصْبَحَ الكَلَامُ أَبْطَأَ.',
  'install.none': 'التَّطْبِيقُ مُثَبَّتٌ بِالفِعْلِ، أَوْ أَنَّ هَذَا المُتَصَفِّحَ لَا يَدْعَمُ التَّثْبِيتَ.',
  'install.tap': 'اضْغَطْ عَلَى الشَّاشَةِ مَرَّةً وَاحِدَةً لِلتَّثْبِيتِ، ثُمَّ اخْتَرْ تَثْبِيت.',
  'install.ios': 'لِتَثْبِيتِ بَصِيرٍ عَلَى الآيْفُون: افْتَحِ المَوْقِعَ فِي سَفَارِي، وَاضْغَطْ زِرَّ المُشَارَكَةِ أَسْفَلَ الشَّاشَةِ، ثُمَّ اخْتَرْ: إِضَافَةٌ إِلَى الشَّاشَةِ الرَّئِيسِيَّةِ.',
  'install.banner': 'ثبّت «بصير» على جهازك ليفتح كتطبيق مستقل من الشاشة الرئيسية.',
  'install.button': 'تثبيت التطبيق',
  'install.iosBanner': 'لتثبيت بصير على الآيفون: اضغط زر المشاركة ⬆️ في سفاري ثم «إضافة إلى الشاشة الرئيسية».',
  'install.listen': 'اسمع الطريقة',
  skipped: 'حَسَنًا. قُلْ ابْدَأْ عِنْدَمَا تُرِيدُ المَشْيَ.',
  notUnderstood: 'لَمْ أَفْهَمْ. قُلْ مُسَاعَدَة لِسَمَاعِ الأَوَامِرِ.',
  langSwitched: 'سَأَتَكَلَّمُ بِالعَرَبِيَّةِ مِنَ الآنَ.',

  // الكاميرا والصور
  'cam.notStarted': 'الكَامِيرَا لَمْ تَبْدَأْ بَعْدُ.',
  'cam.dark': 'المَكَانُ مُظْلِمٌ جِدًّا، أَوْ أَنَّ الكَامِيرَا مُغَطَّاةٌ.',
  'cam.insecure': 'الكَامِيرَا تَحْتَاجُ اتِّصَالًا آمِنًا. افْتَحِ التَّطْبِيقَ عَبْرَ رَابِطٍ آمِنٍ، أَوْ مِنْ نَفْسِ الجِهَازِ.',
  'cam.unsupported': 'هَذَا المُتَصَفِّحُ لَا يَدْعَمُ الكَامِيرَا.',
  'cam.denied': 'لَمْ يُسْمَحْ بِاسْتِخْدَامِ الكَامِيرَا. اسْمَحْ بِهَا مِنْ إِعْدَادَاتِ المُتَصَفِّحِ.',
  'cam.none': 'لَا تُوجَدُ كَامِيرَا فِي هَذَا الجِهَازِ.',
  'cam.busy': 'الكَامِيرَا مُسْتَخْدَمَةٌ مِنْ تَطْبِيقٍ آخَرَ.',
  'cam.failed': 'تَعَذَّرَ تَشْغِيلُ الكَامِيرَا.',
  'tilt.down': 'أَمِلِ الجَوَّالَ لِلْأَسْفَلِ قَلِيلًا.',
  'tilt.up': 'ارْفَعِ الجَوَّالَ قَلِيلًا، فَالكَامِيرَا تَنْظُرُ إِلَى الأَرْضِ.',
  'tilt.upShort': 'ارْفَعِ الجَوَّالَ قَلِيلًا.',
  meta: ({ sec, images, dirs }) => `${sec} ث · ${images} ${images === 1 ? 'صورة' : 'صور'}${dirs ? ' · ' + dirs : ''}`,
  moment: 'لَحْظَة.',
  momentRead: 'لَحْظَة، أَقْرَأ.',
  'label.main': 'الأمام (الجوال الرئيسي)',

  // التنقل
  'nav.goalUpdated': ({ goal }) => `حَسَنًا، سَأُوَجِّهُكَ إِلَى ${goal}.`,
  'nav.already': 'التَّنَقُّلُ يَعْمَلُ بِالفِعْلِ.',
  'nav.startGoal': ({ goal }) => `سَأُوَجِّهُكَ إِلَى ${goal}. أَمْسِكِ الجَوَّالَ أَمَامَ صَدْرِكَ مَعَ إِمَالَةٍ بَسِيطَةٍ لِلْأَسْفَلِ، وَامْشِ بِبُطْءٍ.`,
  'nav.start': 'بَدَأَ التَّنَقُّلُ. أَمْسِكِ الجَوَّالَ أَمَامَ صَدْرِكَ مَعَ إِمَالَةٍ بَسِيطَةٍ لِلْأَسْفَلِ، وَامْشِ بِبُطْءٍ، وَاسْتَمِعْ لِلْإِرْشَادَاتِ.',
  'nav.stoppedSuffix': ' أَوْقَفْتُ التَّنَقُّلَ.',

  // التعرّف على المكان
  'survey.analyzing': 'تَمَّ التَّصْوِيرُ. جَارٍ تَحْلِيلُ المَكَانِ، وَقَدْ يَسْتَغْرِقُ ذَلِكَ بَعْضَ الوَقْتِ.',
  'survey.saved': 'حَفِظْتُ وَصْفَ المَكَانِ. قُلْ ابْدَأْ عِنْدَمَا تُرِيدُ المَشْيَ.',
  'survey.overturn': 'تَجَاوَزْتَ. ارْجِعْ قَلِيلًا إِلَى اليَسَارِ.',
  'four.intro': 'سَأُصَوِّرُ أَرْبَعَ جِهَاتٍ. أَمْسِكِ الجَوَّالَ أَمَامَ صَدْرِكَ، وَالشَّاشَةُ نَحْوَكَ، مَعَ إِمَالَةٍ بَسِيطَةٍ لِلْأَسْفَلِ. بَعْدَ كُلِّ صُورَةٍ اسْتَدِرْ إِلَى اليَمِينِ رُبْعَ دَوْرَةٍ، حَتَّى تَسْمَعَ الصَّفَّارَاتِ تَعْلُو ثُمَّ تَتَوَقَّفُ.',
  'four.turn': 'اسْتَدِرْ إِلَى اليَمِينِ رُبْعَ دَوْرَةٍ.',
  'four.hold': ({ name }) => `${name}. اثْبُتْ.`,
  'four.label': ({ name, angle }) => `${name} (زاوية ${angle} درجة)`,
  'video.intro': 'سَأُصَوِّرُ فِيدْيُو لِلْمَكَانِ. أَمْسِكِ الجَوَّالَ أَمَامَ صَدْرِكَ مَعَ إِمَالَةٍ بَسِيطَةٍ لِلْأَسْفَلِ، ثُمَّ اسْتَدِرْ حَوْلَ نَفْسِكَ إِلَى اليَمِينِ بِبُطْءٍ دَوْرَةً كَامِلَةً. ابْدَأْ بَعْدَ الصَّفَّارَةِ.',
  'video.q1': 'رُبْع',
  'video.q2': 'نِصْف',
  'video.q3': 'ثَلَاثَةُ أَرْبَاع',
  'video.turnSlowly': 'اسْتَدِرْ بِبُطْءٍ إِلَى اليَمِينِ.',
  'video.keepTurning': 'اسْتَمِرَّ فِي الدَّوَرَانِ.',
  'video.notEnough': 'لَمْ أَلْتَقِطْ صُوَرًا كَافِيَةً. حَاوِلْ مَرَّةً أُخْرَى.',
  'video.angleLabel': ({ a, name }) => `زاوية ${a} درجة — ${name}`,
  'video.approxLabel': ({ k, n, a, name }) => `لقطة ${k} من ${n} — تقريباً زاوية ${a} درجة (${name})`,
  'video.fileLabel': ({ i, n, t, a, name }) => `لقطة ${i} من ${n} عند الثانية ${t} — إذا كان التصوير دورة كاملة فالزاوية تقريباً ${a} درجة (${name})`,
  'video.readFail': 'تعذر قراءة الفيديو. جرّب صيغة MP4.',
  'video.noDuration': 'مدة الفيديو غير معروفة. جرّب ملف MP4.',

  // الجوالات الإضافية
  'node.linked': ({ name }) => `تَمَّ رَبْطُ جَوَّالِ ${name}.`,
  'node.lost': ({ name }) => `انْقَطَعَ جَوَّالُ ${name}.`,
  'node.thisIs': ({ name }) => `هَذَا الجَوَّالُ الآنَ كَامِيرَا ${name}.`,
  'node.badge': ({ name }) => `كاميرا ${name}`,
  'node.sending': ({ sec, others }) => `متصل ويرسل الصور كل ${sec} ثانية.${others ? ` جوالات أخرى: ${others}.` : ''} اترك الشاشة مفتوحة.`,
  'node.disconnected': ({ name }) => `انْقَطَعَ اتِّصَالُ كَامِيرَا ${name}.`,
  'node.unlinked': 'تَمَّ فَصْلُ الجَوَّالِ.',

  // الاتجاهات
  'dir.front': 'الأَمَام',
  'dir.right': 'اليَمِين',
  'dir.back': 'الخَلْف',
  'dir.left': 'اليَسَار',
  'dir.frontRight': 'أَمَامَ اليَمِين',
  'dir.backRight': 'خَلْفَ اليَمِين',
  'dir.backLeft': 'خَلْفَ اليَسَار',
  'dir.frontLeft': 'أَمَامَ اليَسَار',

  // أخطاء الاتصال
  'net.badReply': 'رَدٌّ غَيْرُ مَفْهُومٍ مِنْ خَادِمِ بَصِيرٍ.',
  'net.serverError': 'خَطَأٌ مِنَ الخَادِمِ.',
  'net.timeout': 'انْتَهَتْ مُهْلَةُ الاتِّصَالِ. حَاوِلْ مَرَّةً أُخْرَى.',
  'net.unreachable': 'تَعَذَّرَ الاتِّصَالُ بِخَادِمِ بَصِيرٍ. تَأَكَّدْ أَنَّهُ يَعْمَلُ، وَأَنَّ الجَوَّالَ عَلَى نَفْسِ الشَّبَكَةِ.',

  // الصوت
  'voice.sample': 'مَرْحَبًا، هَذَا صَوْتُ بَصِيرٍ. تَقَدَّمْ ثَلَاثَ خُطُوَاتٍ لِلْأَمَامِ، الطَّرِيقُ خَالٍ.',
};

const en = {
  'mode.loading': 'Loading…',
  'mode.idle': 'Tap and speak',
  'mode.idle.sub': 'Say: start — what is in front — read — four directions — video',
  'mode.idleNoStt': 'Tap to start navigation',
  'mode.idleNoStt.sub': 'Tap again to stop',
  'mode.listening': 'Listening…',
  'mode.listening.sub': 'Speak now',
  'mode.thinking': 'One moment…',
  'mode.cancel.sub': 'Tap to cancel',
  'mode.survey': 'Capturing…',
  'mode.nav': 'Navigation is on',
  'mode.nav.sub': 'Tap and say: stop',
  'mode.navNoStt.sub': 'Tap to stop',
  'mode.error': 'Could not start',

  provider: ({ label, model, hasKey }) => `Saved provider: ${label}, model: ${model}. ${hasKey ? 'The key is saved on the server, so you never need to enter it again.' : 'This provider needs no key.'}`,
  'devices.none': ({ link }) => `No extra phones are connected. To link one, open ${link} on the other phone and choose its direction: front, right, back or left. You can link up to 4 phones.`,
  'devices.some': ({ count, names }) => `${count} extra ${count === 1 ? 'phone is' : 'phones are'} connected: ${names}. I use their pictures with every instruction.`,
  'welcome.first': ({ provider }) => `Hello, I am Basir. ${provider}`,
  'welcome.back': ({ label, model }) => `Basir is ready. Provider: ${label}, model: ${model}.`,
  'welcome.memory': ' I have a saved description of this place.',
  'welcome.tapTalkFirst': ' Tap anywhere on the screen and speak after the beep. Say start, and I will guide your walk, or say help.',
  'welcome.tapTalk': ' Tap and speak.',
  'welcome.tapToggle': ' Tap anywhere on the screen to start or stop navigation.',
  'welcome.firstTime': ' This is the first time. To learn this place, say video, then turn slowly around yourself. Or say four directions, and I will capture each side. Or say skip.',
  'welcome.install': ' You can install the app on this device: say install.',
  help: 'Commands: start, to navigate. Take me to the door, to walk to a goal. Stop. What is in front. Read, to read text. Four directions, or video, to learn the place. Where is the chair, or any question about what is in front of you. Provider. Phones. Forget the place. Faster, or slower, for speech speed. Repeat. Arabic, to speak Arabic. Settings.',
  installed: 'The app is installed. You can open it from the home screen.',
  'server.openSetup': ' — open settings to set the server address.',
  insecure: 'Warning: the camera and microphone need a secure link (HTTPS), or opening the app on the same computer via localhost. See README.',
  tapToStart: 'Tap anywhere on the screen to begin.',

  cancelled: 'Cancelled.',
  navStopped: 'Navigation stopped.',
  didntHear: 'I did not hear anything. Tap and speak again.',
  'mic.denied': 'Microphone access was denied. Allow it in the browser settings.',
  'mic.none': 'No working microphone was found.',
  'mic.network': 'Speech recognition needs internet. Please try again.',
  'mic.unavailable': 'Voice commands are not available in this browser. Tap the screen to start navigation, and tap again to stop.',
  'mic.failed': 'Could not start the microphone. Tap and try again.',
  stopped: 'Stopped.',
  nothingToRepeat: 'There is nothing to repeat.',
  openingSettings: 'Opening the settings page. A sighted helper is needed to complete it.',
  forgot: 'I forgot the description of this place.',
  faster: 'Speech is faster now.',
  slower: 'Speech is slower now.',
  'install.none': 'The app is already installed, or this browser cannot install it.',
  'install.tap': 'Tap the screen once to install, then choose install.',
  'install.ios': 'To install Basir on iPhone: open the site in Safari, tap the share button at the bottom, then choose Add to Home Screen.',
  'install.banner': 'Install Basir on this device so it opens as an app from the home screen.',
  'install.button': 'Install app',
  'install.iosBanner': 'To install Basir on iPhone: tap the Share button ⬆️ in Safari, then “Add to Home Screen”.',
  'install.listen': 'Hear how',
  skipped: 'Okay. Say start when you want to walk.',
  notUnderstood: 'I did not understand. Say help to hear the commands.',
  langSwitched: 'I will speak English from now on.',

  'cam.notStarted': 'The camera has not started yet.',
  'cam.dark': 'It is too dark, or the camera is covered.',
  'cam.insecure': 'The camera needs a secure HTTPS connection. Open the app through a secure link, or on the same computer via localhost.',
  'cam.unsupported': 'This browser does not support the camera.',
  'cam.denied': 'Camera access was denied. Allow it in the browser settings.',
  'cam.none': 'This device has no camera.',
  'cam.busy': 'The camera is being used by another app.',
  'cam.failed': 'Could not start the camera.',
  'tilt.down': 'Tilt the phone down a little.',
  'tilt.up': 'Raise the phone a little, the camera is looking at the floor.',
  'tilt.upShort': 'Raise the phone a little.',
  meta: ({ sec, images, dirs }) => `${sec} s · ${images} ${images === 1 ? 'image' : 'images'}${dirs ? ' · ' + dirs : ''}`,
  moment: 'One moment.',
  momentRead: 'One moment, reading.',
  'label.main': 'front (main phone)',

  'nav.goalUpdated': ({ goal }) => `Okay, I will guide you to ${goal}.`,
  'nav.already': 'Navigation is already on.',
  'nav.startGoal': ({ goal }) => `I will guide you to ${goal}. Hold the phone in front of your chest, tilted down a little, and walk slowly.`,
  'nav.start': 'Navigation started. Hold the phone in front of your chest, tilted down a little, walk slowly and listen to the instructions.',
  'nav.stoppedSuffix': ' I stopped navigation.',

  'survey.analyzing': 'Done capturing. Analysing the place, this may take a moment.',
  'survey.saved': 'I saved the description of this place. Say start when you want to walk.',
  'survey.overturn': 'You turned too far. Turn back a little to the left.',
  'four.intro': 'I will capture four directions. Hold the phone in front of your chest, screen facing you, tilted down a little. After each picture, turn a quarter turn to the right until the rising beeps stop.',
  'four.turn': 'Turn a quarter turn to the right.',
  'four.hold': ({ name }) => `${name}. Hold still.`,
  'four.label': ({ name, angle }) => `${name} (${angle} degrees)`,
  'video.intro': 'I will record the place. Hold the phone in front of your chest, tilted down a little, then turn slowly to the right for one full circle. Start after the beep.',
  'video.q1': 'Quarter',
  'video.q2': 'Half',
  'video.q3': 'Three quarters',
  'video.turnSlowly': 'Turn slowly to the right.',
  'video.keepTurning': 'Keep turning.',
  'video.notEnough': 'I did not capture enough pictures. Please try again.',
  'video.angleLabel': ({ a, name }) => `${a} degrees — ${name}`,
  'video.approxLabel': ({ k, n, a, name }) => `frame ${k} of ${n} — about ${a} degrees (${name})`,
  'video.fileLabel': ({ i, n, t, a, name }) => `frame ${i} of ${n} at ${t} s — if the video is one full circle, about ${a} degrees (${name})`,
  'video.readFail': 'Could not read the video. Try an MP4 file.',
  'video.noDuration': 'Unknown video length. Try an MP4 file.',

  'node.linked': ({ name }) => `The ${name} phone is linked.`,
  'node.lost': ({ name }) => `The ${name} phone disconnected.`,
  'node.thisIs': ({ name }) => `This phone is now the ${name} camera.`,
  'node.badge': ({ name }) => `${name} camera`,
  'node.sending': ({ sec, others }) => `Connected, sending a picture every ${sec} seconds.${others ? ` Other phones: ${others}.` : ''} Keep the screen on.`,
  'node.disconnected': ({ name }) => `The ${name} camera lost its connection.`,
  'node.unlinked': 'The phone was unlinked.',

  'dir.front': 'front',
  'dir.right': 'right',
  'dir.back': 'back',
  'dir.left': 'left',
  'dir.frontRight': 'front right',
  'dir.backRight': 'back right',
  'dir.backLeft': 'back left',
  'dir.frontLeft': 'front left',

  'net.badReply': 'The Basir server sent an unreadable reply.',
  'net.serverError': 'Server error.',
  'net.timeout': 'The connection timed out. Please try again.',
  'net.unreachable': 'Cannot reach the Basir server. Make sure it is running and the phone is on the same network.',

  'voice.sample': 'Hello, this is Basir. Walk 3 steps forward, the way is clear.',
};

export const STRINGS = { ar, en };
export const LANGS = ['ar', 'en'];

let current = 'ar';

export function setLang(lang) {
  current = STRINGS[lang] ? lang : 'ar';
  return current;
}

export function getLang() {
  return current;
}

/** نص مترجم. القيم إما نص ثابت أو دالة تأخذ متغيرات. */
export function t(key, vars = {}) {
  const v = STRINGS[current][key] ?? STRINGS.ar[key];
  if (v === undefined) return key;
  return typeof v === 'function' ? v(vars) : v;
}

/** فاصل القوائم المنطوقة: «، » بالعربية و «, » بالإنجليزية. */
export const listSep = () => (current === 'ar' ? '، ' : ', ');

/** يحذف التشكيل للعرض على الشاشة (النطق يستخدم النص المشكول). */
export const plain = (text) => String(text || '').replace(/[\u064B-\u0652\u0670]/g, '');
