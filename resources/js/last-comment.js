/**
 * g7-forum-addon — 포럼 글을 열면 마지막 댓글로 한 번 스크롤한다 (1.6.0)
 *
 * plugin.json `assets.js.output` 로 전 페이지에 로드된다(loading.strategy = global). 빌드 단계가
 * 없는 순수 JS 이고 `entry` 를 두지 않아 코어 빌드 대상도 아니다 — 이 파일이 곧 배포본이다.
 *
 * 동작
 *  - 글 보기 주소(/board/{slug}/{id})이고, 포럼 위젯 표식(`[data-g7fa-forum]`)이 DOM 에 있을 때만.
 *    위젯은 포럼 게시판 글에서만 렌더되므로(BoardShowWidgetListener::widgetNode 의 `if`) 일반·갤러리
 *    게시판에는 아무 일도 하지 않는다.
 *  - 표식이 나타난 뒤 DOM 이 SETTLE_MS 동안 조용해지면 한 번 판정한다. 그 시점에 댓글 행
 *    (`#post_comments [data-g7-anchor="comment-row"]`, 템플릿 표식)이 있으면 마지막 행으로 스크롤,
 *    없으면 이동하지 않는다. 판정은 주소마다 한 번이다 — 이후 댓글을 쓰거나 지워 DOM 이 바뀌어도
 *    다시 움직이지 않는다(사용자 스크롤을 방해하지 않음).
 *  - 판정 전에 사용자가 이미 스크롤을 시작했으면(휠·터치 끌기·스크롤 키) 이동하지 않는다.
 *  - 페이지 나눔·특정 댓글 주소(해시)·읽은 위치 저장은 다루지 않는다.
 *
 * SPA 대응: 주소(pathname)가 바뀌면 상태를 새로 잡는다. DOM 변화는 MutationObserver 로 받고,
 * requestAnimationFrame 한 번으로 묶어 판정한다.
 */
(function () {
  'use strict';

  if (window.__g7faLastComment) {
    return;
  }
  window.__g7faLastComment = true;

  var FORUM_MARKER = '[data-g7fa-forum]';
  var ROW_SELECTOR = '#post_comments [data-g7-anchor="comment-row"]';
  /** 글 보기 주소 — /board/{slug}/{id} (글쓰기 /board/{slug}/write 는 제외) */
  var SHOW_PATH = /^\/board\/[^/]+\/([^/]+)\/?$/;
  /** 표식이 나타난 뒤 DOM 이 이만큼 조용하면 댓글이 다 그려진 것으로 본다 */
  var SETTLE_MS = 300;
  /** 고정 헤더(h-14)와 댓글 섹션 scroll-mt-20 에 맞춘 위쪽 여백 */
  var TOP_OFFSET = 80;
  /** 사용자가 스크롤을 시작했다고 보는 키 */
  var SCROLL_KEYS = { ArrowDown: 1, ArrowUp: 1, PageDown: 1, PageUp: 1, End: 1, Home: 1, ' ': 1 };

  var currentPath = null;
  var decided = false;
  var userScrolled = false;
  var settleTimer = null;
  var frame = null;

  function isShowPath(path) {
    var m = SHOW_PATH.exec(path);
    return !!m && m[1] !== 'write';
  }

  function resetFor(path) {
    currentPath = path;
    decided = false;
    userScrolled = false;
    if (settleTimer !== null) {
      clearTimeout(settleTimer);
      settleTimer = null;
    }
  }

  function decide() {
    settleTimer = null;
    if (decided || location.pathname !== currentPath || !document.querySelector(FORUM_MARKER)) {
      return;
    }
    decided = true;
    if (userScrolled) {
      return;
    }
    var rows = document.querySelectorAll(ROW_SELECTOR);
    if (rows.length === 0) {
      return;
    }
    var last = rows[rows.length - 1];
    var top = last.getBoundingClientRect().top + window.scrollY - TOP_OFFSET;
    window.scrollTo({ top: Math.max(top, 0), behavior: 'smooth' });
  }

  function check() {
    frame = null;
    var path = location.pathname;
    if (path !== currentPath) {
      resetFor(path);
    }
    if (decided || !isShowPath(path) || !document.querySelector(FORUM_MARKER)) {
      return;
    }
    // DOM 이 바뀔 때마다 대기를 다시 건다 — 마지막 변화 뒤 SETTLE_MS 에 한 번 판정
    if (settleTimer !== null) {
      clearTimeout(settleTimer);
    }
    settleTimer = setTimeout(decide, SETTLE_MS);
  }

  function schedule() {
    if (frame === null) {
      frame = window.requestAnimationFrame(check);
    }
  }

  function markUserScroll() {
    if (!decided) {
      userScrolled = true;
    }
  }

  window.addEventListener('wheel', markUserScroll, { passive: true, capture: true });
  window.addEventListener('touchmove', markUserScroll, { passive: true, capture: true });
  window.addEventListener(
    'keydown',
    function (e) {
      if (SCROLL_KEYS[e.key]) {
        markUserScroll();
      }
    },
    { capture: true }
  );
  window.addEventListener('popstate', schedule);

  function start() {
    new MutationObserver(schedule).observe(document.body, { childList: true, subtree: true });
    schedule();
  }

  if (document.body) {
    start();
  } else {
    document.addEventListener('DOMContentLoaded', start);
  }
})();
