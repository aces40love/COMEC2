(function () {
  "use strict";

  const toggle = document.querySelector("[data-nav-toggle]");
  const nav = document.querySelector("[data-site-nav]");

  function setNav(open) {
    if (!toggle || !nav) return;
    toggle.setAttribute("aria-expanded", String(open));
    toggle.setAttribute("aria-label", open ? "Close navigation" : "Open navigation");
    nav.dataset.open = String(open);
    document.body.classList.toggle("nav-open", open);
  }

  if (toggle && nav) {
    toggle.addEventListener("click", function () {
      setNav(toggle.getAttribute("aria-expanded") !== "true");
    });

    nav.addEventListener("click", function (event) {
      if (event.target.closest("a")) setNav(false);
    });

    document.addEventListener("keydown", function (event) {
      if (event.key === "Escape") {
        setNav(false);
        toggle.focus();
      }
    });

    window.addEventListener("resize", function () {
      if (window.matchMedia("(min-width: 60.01rem)").matches) setNav(false);
    });
  }

  document.querySelectorAll("[data-current-year]").forEach(function (node) {
    node.textContent = new Date().getFullYear();
  });

  document.querySelectorAll("[data-video-id]").forEach(function (frame) {
    const button = frame.querySelector("[data-video-launch]");
    const videoId = frame.dataset.videoId;
    const watchUrl = frame.dataset.videoUrl || "https://www.youtube.com/watch?v=" + videoId;

    if (!button || !videoId) return;

    button.addEventListener("click", function () {
      if (window.location.protocol === "file:") {
        window.open(watchUrl, "_blank", "noopener,noreferrer");
        return;
      }

      const iframe = document.createElement("iframe");
      const embedParams = new URLSearchParams({
        autoplay: "1",
        playsinline: "1",
        rel: "0"
      });

      if (window.location.origin && window.location.origin !== "null") {
        embedParams.set("origin", window.location.origin);
      }

      iframe.src = "https://www.youtube-nocookie.com/embed/" + encodeURIComponent(videoId) + "?" + embedParams.toString();
      iframe.title = "An Introduction to COMEC";
      iframe.loading = "eager";
      iframe.referrerPolicy = "strict-origin-when-cross-origin";
      iframe.allow = "accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share";
      iframe.allowFullscreen = true;
      frame.replaceChildren(iframe);
      frame.dataset.videoLoaded = "true";
    });
  });
})();
