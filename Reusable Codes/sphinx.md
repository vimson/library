

Sphinx Reusable Codes
=====================

 - Code which we can use while developing full text search engines

 - RT Index memory concern http://www.ivinco.com/blog/sphinx-in-action-good-and-bad-in-sphinx-real-time-indexes/
	

SphinxSQL
```sql

mysql> SELECT * FROM index WHERE MATCH('"a quorum search is made here"/4') ORDER BY WEIGHT() DESC, id ASC OPTION ranker = expr( 'sum( exact_hit+10*(min_hit_pos==1)+lcs*(0.1*my_attr) )*1000 + bm25' );

mysql> SELECT * FROM myindex WHERE MATCH(‘@(title,content) find me fast’);

```


References
========
http://www.slideshare.net/AdrianNuta1/advanced-fulltext-search-with-sphinx-30757993?next_slideshow=1
