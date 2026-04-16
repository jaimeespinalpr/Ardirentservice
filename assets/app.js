const revealTargets = document.querySelectorAll(
  [
    ".hero-copy",
    ".hero-card",
    ".feature-card",
    ".equipment-card",
    ".inventory-strip",
    ".process-panel",
    ".vision-card",
    ".contact-card",
    ".section",
  ].join(", ")
);

revealTargets.forEach((element) => {
  element.classList.add("reveal");
});

const observer = new IntersectionObserver(
  (entries) => {
    entries.forEach((entry) => {
      if (entry.isIntersecting) {
        entry.target.classList.add("is-visible");
        observer.unobserve(entry.target);
      }
    });
  },
  { threshold: 0.16 }
);

revealTargets.forEach((element) => observer.observe(element));
