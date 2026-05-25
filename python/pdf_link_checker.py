from pypdf import PdfReader
import requests
import json
import argparse
from urllib.parse import urlparse
import os
import sys
from datetime import datetime
from urllib.parse import urlparse


TEST_DOMAINS = {
    "test0.example.com",
    "test1.example.com",
    "qa.example.com",
    "tmp.example.com",
    "beta-test.example.com"
}

def is_test_env(url, final_url=None):
    try:
        domain = urlparse(url).netloc.lower()
        final_domain = urlparse(final_url).netloc.lower() if final_url else ""

        return domain in TEST_DOMAINS or final_domain in TEST_DOMAINS
    except:
        return False

def now_iso():
    return datetime.now().astimezone().isoformat()


def load_input(path):
    with open(path, "r", encoding="utf-8") as f:
        return json.load(f)


def download_pdf(url):
    r = requests.get(url, timeout=20)
    r.raise_for_status()
    return r.content


def extract_links_from_pdf(data):
    from io import BytesIO

    reader = PdfReader(BytesIO(data))

    links = []

    for page_num, page in enumerate(reader.pages, start=1):
        if "/Annots" not in page:
            continue

        for annot in page["/Annots"]:
            obj = annot.get_object()

            if "/A" in obj and "/URI" in obj["/A"]:
                url = obj["/A"]["/URI"]

                links.append({
                    "url": str(url),
                    "page": page_num
                })

    return links, len(reader.pages)


def check_link(url, timeout=5):
    try:
        r = requests.head(url, timeout=timeout, allow_redirects=True)
        return r.status_code, r.url, None
    except Exception as e:
        return None, None, str(e)


def main():

    parser = argparse.ArgumentParser()
    parser.add_argument("--input-json", required=True)

    args = parser.parse_args()

    cfg = load_input(args.input_json)

    source = cfg["source"]
    paths = cfg["paths"]

    if source["type"] == "url":
        pdf_data = download_pdf(source["value"])
    else:
        with open(paths["input_pdf"], "rb") as f:
            pdf_data = f.read()

    links, pages = extract_links_from_pdf(pdf_data)

    results = []

    for link in links:
        status, final_url, err = check_link(link["url"])

        test_env = is_test_env(link["url"], final_url)

        results.append({
            "url": link["url"],
            "page": link["page"],
            "status_code": status,
            "final_url": final_url,
            "error": err,
            "is_test_env": test_env
        })

    test_env_count = sum(1 for r in results if r["is_test_env"])
    
    result = {
        "status": "ok",
        "job_id": cfg["job_id"],
        "processed_at": now_iso(),
        "source": source,
        "pdf": {
            "pages": pages
        },
        "summary": {
            "total_links": len(results),
            "unique_links": len(set([x["url"] for x in results])),
            "test_env_links": test_env_count
        },
        "links": results,
        "errors": []
    }

    status_groups = {
        "2xx": 0,
        "3xx": 0,
        "4xx": 0,
        "5xx": 0,
        "errors": 0,
        "test_env": 0
    }

    for r in results:
        if r["is_test_env"]:
            status_groups["test_env"] += 1

        if r["error"]:
            status_groups["errors"] += 1
        elif r["status_code"] is None:
            status_groups["errors"] += 1
        elif 200 <= r["status_code"] < 300:
            status_groups["2xx"] += 1
        elif 300 <= r["status_code"] < 400:
            status_groups["3xx"] += 1
        elif 400 <= r["status_code"] < 500:
            status_groups["4xx"] += 1
        elif r["status_code"] >= 500:
            status_groups["5xx"] += 1

    problem_links = []

    for r in results:
        if r["is_test_env"]:
            reason = "TEST ENV LINK"
        elif r["error"]:
            reason = r["error"]
        elif r["status_code"] is not None and r["status_code"] >= 400:
            reason = str(r["status_code"])
        else:
            continue

        problem_links.append(
            f'Page {r["page"]}: {r["url"]} — {reason}'
        )

    jira_report_text = "\n".join(problem_links)

    result = {
        "status": "ok",
        "job_id": cfg["job_id"],
        "processed_at": now_iso(),
        "source": source,
        "pdf": {
            "pages": pages
        },
        "summary": {
            "total_links": len(results),
            "unique_links": len(set([x["url"] for x in results])),
            "test_env_links": test_env_count,
            "status_groups": status_groups
        },
        "jira_report": {
            "text": jira_report_text
        },
        "links": results,
        "errors": []
    }


    with open(paths["result_json"], "w", encoding="utf-8") as f:
        json.dump(result, f, indent=2)


if __name__ == "__main__":
    main()